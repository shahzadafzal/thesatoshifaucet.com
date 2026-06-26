<?php


function decodeBolt11Invoice(string $invoice): array
{
    $invoice = strtolower(trim($invoice));

    if (!str_starts_with($invoice, 'ln')) {
        throw new RuntimeException('Invalid BOLT11 invoice.');
    }

    $pos = strrpos($invoice, '1');
    if ($pos === false) {
        throw new RuntimeException('Invalid BOLT11 invoice: missing separator.');
    }

    $hrp = substr($invoice, 0, $pos);

    // Example HRP:
    // lnbc100n
    // lnbc1u
    // lnbc2500n
    if (!preg_match('/^ln(bc|tb|bcrt)([0-9]*)([munp]?)$/', $hrp, $m)) {
        throw new RuntimeException('Invalid or unsupported BOLT11 HRP.');
    }

    $network = $m[1];       // bc = mainnet, tb = testnet
    $amount  = $m[2];       // numeric part
    $unit    = $m[3];       // m/u/n/p

    $amountMsat = null;

    if ($amount !== '') {
        $amountNum = (int)$amount;

        switch ($unit) {
            case 'm': // milli-BTC
                $amountMsat = $amountNum * 100000000;
                break;

            case 'u': // micro-BTC
                $amountMsat = $amountNum * 100000;
                break;

            case 'n': // nano-BTC
                $amountMsat = $amountNum * 100;
                break;

            case 'p': // pico-BTC
                $amountMsat = intdiv($amountNum, 10);
                break;

            default: // BTC
                $amountMsat = $amountNum * 100000000000;
                break;
        }
    }

    return [
        'invoice'      => $invoice,
        'hrp'          => $hrp,
        'network'      => $network,
        'amount_msat'  => $amountMsat,
        'amount_sats'  => $amountMsat !== null ? $amountMsat / 1000 : null,
        'has_amount'   => $amountMsat !== null,
    ];
}

?>