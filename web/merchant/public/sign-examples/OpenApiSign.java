import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.security.SecureRandom;
import java.util.HashMap;
import java.util.Map;
import java.util.TreeMap;
import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;

/**
 * 开放 API 签名示例（Java 8+，只用 JDK）。
 *
 * 签名规则：除 sign 外的全部参数（公共参数 + 业务参数）按参数名字母升序排列，
 * 拼成 k1=v1&k2=v2（值用原文，不做 URL 编码），以 AppSecret 为密钥做 HMAC-SHA256，
 * 结果为 64 位小写十六进制字符串。平台回调商户时用同样的规则签名。
 *
 * 运行：javac OpenApiSign.java && java OpenApiSign
 */
public class OpenApiSign {

    public static String sign(Map<String, String> params, String appSecret) {
        StringBuilder text = new StringBuilder();
        for (Map.Entry<String, String> e : new TreeMap<>(params).entrySet()) {
            if ("sign".equals(e.getKey())) {
                continue;
            }
            if (text.length() > 0) {
                text.append('&');
            }
            text.append(e.getKey()).append('=').append(e.getValue());
        }
        try {
            Mac mac = Mac.getInstance("HmacSHA256");
            mac.init(new SecretKeySpec(appSecret.getBytes(StandardCharsets.UTF_8), "HmacSHA256"));
            byte[] digest = mac.doFinal(text.toString().getBytes(StandardCharsets.UTF_8));
            StringBuilder hex = new StringBuilder();
            for (byte b : digest) {
                hex.append(String.format("%02x", b));
            }
            return hex.toString();
        } catch (Exception e) {
            throw new IllegalStateException(e);
        }
    }

    /** 校验平台回调：签名一致，且 timestamp 在 5 分钟以内。 */
    public static boolean verify(Map<String, String> params, String appSecret) {
        String sign = params.get("sign");
        String timestamp = params.get("timestamp");
        if (sign == null || timestamp == null) {
            return false;
        }
        try {
            if (Math.abs(System.currentTimeMillis() / 1000 - Long.parseLong(timestamp)) > 300) {
                return false;
            }
        } catch (NumberFormatException e) {
            return false;
        }
        return MessageDigest.isEqual(
            sign(params, appSecret).getBytes(StandardCharsets.UTF_8),
            sign.getBytes(StandardCharsets.UTF_8));
    }

    public static void main(String[] args) {
        // ---- 调用示例：查询余额 ----
        String baseUrl = "https://your-platform-host/open-api"; // 换成接口文档页上显示的地址
        String appKey = "your_app_key";
        String appSecret = "your_app_secret";

        byte[] random = new byte[16];
        new SecureRandom().nextBytes(random);
        StringBuilder nonce = new StringBuilder();
        for (byte b : random) {
            nonce.append(String.format("%02x", b));
        }

        Map<String, String> params = new HashMap<>();
        params.put("app_key", appKey);
        params.put("timestamp", String.valueOf(System.currentTimeMillis() / 1000));
        params.put("nonce", nonce.toString());
        params.put("sign", sign(params, appSecret));
        // 发送时参数值需要 URL 编码（URLEncoder.encode(value, "UTF-8")），签名时不编码
        System.out.println(baseUrl + "/balance?app_key=" + params.get("app_key")
            + "&timestamp=" + params.get("timestamp") + "&nonce=" + params.get("nonce")
            + "&sign=" + params.get("sign"));

        // ---- 回调接收：把表单参数放进 Map<String, String> 调 verify()，通过后响应 success ----

        // ---- 自检：用固定参数计算，结果应与接口文档页上的示例签名一致 ----
        Map<String, String> demo = new HashMap<>();
        demo.put("app_key", "ak_demo");
        demo.put("timestamp", "1758153600");
        demo.put("nonce", "e4b1c2d3f4a5b6c7");
        demo.put("business_line", "recharge");
        System.out.println(sign(demo, "demo_secret"));
    }
}
