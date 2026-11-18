<?php
// lnurl-guard.php
// Anti-abuse checks shared by claim.php (faucet) and block-race.php / block-race-lib.php (Block Race).
// Added 2026-11-18 after a scripted farmer (curl/8.14.1, rotating proxies) claimed many times with ONE coinos
// wallet by adding "?c=<number>" to its LNURL so every claim looked like a new wallet. See CHANGELOG.md.
//
//   lg_script_ua($ua)        -> true when the request comes from a command-line/script HTTP tool, not a browser
//   lg_check_lnurl($lnurl)   -> decodes the LNURL (checksum verified) and makes sure it's a receive (pay) link
//   lg_lnurl_key($lnurl)     -> "wallet key" = host + path of the decoded URL, without ?query or #fragment,
//                               so "…/lnurlp/alice?c=1" and "…/lnurlp/alice?c=2" count as the same wallet

// Request is from a scripting tool rather than a web browser (or sends no browser ID at all).
function lg_script_ua(string $ua): bool {
    $ua = trim($ua);
    if ($ua === '') return true;
    // Tools that put their own name first: curl/8.14.1, Wget/1.21, python-requests/2.32, Go-http-client/2.0 ...
    if (preg_match('#^(curl|wget|python-requests|python-urllib|python-httpx|httpx|aiohttp|go-http-client|java/|okhttp|'
                 . 'libwww-perl|lwp::|php/|guzzlehttp|axios|node-fetch|undici|got |postmanruntime|insomnia|httpie|scrapy)#i', $ua)) {
        return true;
    }
    // Automated (headless) Chrome announces itself inside an otherwise normal browser ID.
    return stripos($ua, 'HeadlessChrome') !== false;
}

// Decode a bech32 LNURL to its URL. Returns null if it isn't valid bech32 or the checksum is wrong
// (a typo or a hand-edited character).
function lg_bech32_decode_url(string $lnurl): ?string {
    $s = strtolower(trim($lnurl));
    if (strpos($s, 'lnurl1') !== 0 || strlen($s) > 2048) return null;
    $pos = strrpos($s, '1');
    $hrp = substr($s, 0, $pos);
    $data = substr($s, $pos + 1);
    if (strlen($data) < 7) return null;

    $charset = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
    $values = [];
    for ($i = 0, $n = strlen($data); $i < $n; $i++) {
        $v = strpos($charset, $data[$i]);
        if ($v === false) return null;
        $values[] = $v;
    }

    // Checksum (BIP-173 polymod; LNURL uses plain bech32, constant 1)
    $gen = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
    $expand = [];
    for ($i = 0, $n = strlen($hrp); $i < $n; $i++) $expand[] = ord($hrp[$i]) >> 5;
    $expand[] = 0;
    for ($i = 0, $n = strlen($hrp); $i < $n; $i++) $expand[] = ord($hrp[$i]) & 31;
    $chk = 1;
    foreach (array_merge($expand, $values) as $v) {
        $top = $chk >> 25;
        $chk = (($chk & 0x1ffffff) << 5) ^ $v;
        for ($i = 0; $i < 5; $i++) {
            if (($top >> $i) & 1) $chk ^= $gen[$i];
        }
    }
    if ($chk !== 1) return null;

    // 5-bit groups -> bytes (drop the 6 checksum characters)
    $acc = 0; $bits = 0; $out = '';
    foreach (array_slice($values, 0, -6) as $v) {
        $acc = ($acc << 5) | $v;
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $out .= chr(($acc >> $bits) & 0xff);
        }
        $acc &= (1 << $bits) - 1;
    }
    return $out;
}

// Checks one LNURL. Returns ['ok' => true, 'url' => ..., 'key' => ...] or ['ok' => false, 'message' => ...].
function lg_check_lnurl(string $lnurl): array {
    $url = lg_bech32_decode_url($lnurl);
    if ($url === null) {
        return ['ok' => false, 'message' => 'That LNURL is damaged (one or more characters are wrong). Please copy it again from your wallet.'];
    }

    $p = parse_url($url);
    $scheme = strtolower($p['scheme'] ?? '');
    $host = strtolower($p['host'] ?? '');
    $isOnion = substr($host, -6) === '.onion';
    if ($host === '' || !($scheme === 'https' || ($scheme === 'http' && $isOnion))) {
        return ['ok' => false, 'message' => 'That LNURL doesn\'t point to a valid wallet address. Please copy your receive LNURL from your wallet.'];
    }

    // LNURL-auth (tag=login), LNURL-withdraw and LNURL-channel links carry their tag/k1 in the link itself.
    // A receive (LNURL-pay) link never does, or says tag=payRequest.
    parse_str($p['query'] ?? '', $q);
    $tag = isset($q['tag']) && is_string($q['tag']) ? $q['tag'] : '';
    if (($tag !== '' && $tag !== 'payRequest') || isset($q['k1'])) {
        return ['ok' => false, 'message' => 'That is a login or withdraw link, not a receive LNURL. In your wallet, open "Receive" and copy the LNURL shown there.'];
    }

    return ['ok' => true, 'url' => $url, 'key' => lg_url_key($url)];
}

// host[:port]/path, lower-case host, no trailing slash, no ?query or #fragment.
function lg_url_key(string $url): string {
    $p = parse_url($url);
    $key = strtolower($p['host'] ?? '');
    if (!empty($p['port'])) $key .= ':' . (int)$p['port'];
    return $key . rtrim($p['path'] ?? '', '/');
}

// Wallet key of a stored LNURL, or null if it can't be decoded. Cached per request.
function lg_lnurl_key(?string $lnurl): ?string {
    static $cache = [];
    if ($lnurl === null || $lnurl === '') return null;
    $s = strtolower(trim($lnurl));
    if (!array_key_exists($s, $cache)) {
        $url = lg_bech32_decode_url($s);
        $cache[$s] = $url === null ? null : lg_url_key($url);
    }
    return $cache[$s];
}
