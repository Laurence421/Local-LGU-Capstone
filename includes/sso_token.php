<?php
// SSO token validation (HS256 JWT) used by sso_login.php
function validateSSOToken($token, $secret)
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    [$header, $payload, $signature] = $parts;

    $headerData = json_decode(ssoBase64UrlDecode($header), true);
    if (!is_array($headerData) || ($headerData['alg'] ?? '') !== 'HS256') {
        return null;
    }

    $expectedSignature = ssoBase64UrlEncode(hash_hmac('sha256', $header . '.' . $payload, $secret, true));
    if (!hash_equals($expectedSignature, $signature)) {
        return null;
    }

    $data = json_decode(ssoBase64UrlDecode($payload), true);
    if (!is_array($data) || !isset($data['exp']) || !is_numeric($data['exp'])) {
        return null;
    }

    $now = time();
    if ($data['exp'] < $now || $data['exp'] > $now + 300) {
        return null;
    }
    if (isset($data['iat']) && $data['iat'] > $now + 30) {
        return null;
    }

    return $data;
}

function ssoBase64UrlEncode($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function ssoBase64UrlDecode($data)
{
    return base64_decode(strtr($data, '-_', '+/'));
}
