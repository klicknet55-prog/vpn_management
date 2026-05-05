<?php
/**
 * iPaymu Service
 *
 * Wrapper untuk iPaymu API v2.
 * Docs: https://documenter.getpostman.com/view/7508948/SWTEapjU
 *
 * Signature algorithm:
 *   SHA256( METHOD + ":" + VA + ":" + SHA256(jsonBody) + ":" + API_KEY )
 */
class IPaymuService
{
    private string $va;
    private string $apiKey;
    private string $baseUrl;

    public function __construct(string $va, string $apiKey, string $baseUrl)
    {
        $this->va      = $va;
        $this->apiKey  = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Buat payment (redirect / VA).
     *
     * @param array $params {
     *   product      string[]  Nama produk
     *   qty          int[]     Kuantitas tiap produk
     *   price        int[]     Harga tiap produk (IDR)
     *   amount       int       Total (wajib cocok)
     *   returnUrl    string    URL setelah bayar sukses
     *   cancelUrl    string    URL jika dibatalkan
     *   notifyUrl    string    Webhook URL
     *   buyerName    string
     *   buyerEmail   string
     *   buyerPhone   string
     *   referenceId  string    Nomor invoice kita
     *   expired      int       Masa berlaku invoice (jam, default 24)
     * }
     *
     * Return: ['ok' => bool, 'session_id' => string, 'payment_url' => string, 'raw' => array]
     */
    public function createPayment(array $params): array
    {
        $body = [
            'product'      => $params['product'],
            'qty'          => $params['qty'],
            'price'        => $params['price'],
            'amount'       => $params['amount'],
            'returnUrl'    => $params['returnUrl'],
            'cancelUrl'    => $params['cancelUrl'],
            'notifyUrl'    => $params['notifyUrl'],
            'buyerName'    => $params['buyerName']  ?? '',
            'buyerEmail'   => $params['buyerEmail'] ?? '',
            'buyerPhone'   => $params['buyerPhone'] ?? '',
            'referenceId'  => $params['referenceId'] ?? '',
            'expired'      => $params['expired']     ?? 24,
            'paymentMethod'=> $params['paymentMethod'] ?? '',
            'paymentChannel'=> $params['paymentChannel'] ?? '',
        ];

        $result = $this->post('/payment', $body);

        if (!$result['ok']) {
            return $result;
        }

        $data       = $result['data'] ?? [];
        $sessionId  = $data['SessionID'] ?? $data['session_id'] ?? '';
        $paymentUrl = $data['Url'] ?? $data['payment_url'] ?? $data['url'] ?? '';

        return [
            'ok'          => true,
            'session_id'  => $sessionId,
            'payment_url' => $paymentUrl,
            'raw'         => $data,
        ];
    }

    /**
     * Cek status transaksi berdasarkan ID transaksi iPaymu.
     */
    public function checkTransaction(string $transactionId): array
    {
        return $this->post('/check-transaction', ['id' => $transactionId]);
    }

    // ── internal ──────────────────────────────────────────────────────────────

    private function post(string $path, array $body): array
    {
        $url     = $this->baseUrl . $path;
        $json    = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $method  = 'POST';

        $signature = hash('sha256', strtolower($method) . ':' . $this->va . ':' . hash('sha256', $json) . ':' . $this->apiKey);

        $headers = [
            'Content-Type: application/json',
            'va: '        . $this->va,
            'signature: ' . $signature,
            'timestamp: ' . date('YmdHis'),
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw    = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['ok' => false, 'error' => 'cURL error: ' . $err, 'data' => null];
        }

        $decoded = json_decode($raw, true);
        if ($decoded === null) {
            return ['ok' => false, 'error' => 'Invalid JSON response from iPaymu', 'data' => null];
        }

        // iPaymu mengembalikan status di field "Status" (integer)
        $apiStatus = (int) ($decoded['Status'] ?? $decoded['status'] ?? $status);
        if ($apiStatus !== 200) {
            $msg = $decoded['Message'] ?? $decoded['message'] ?? $decoded['error'] ?? 'iPaymu error';
            return ['ok' => false, 'error' => $msg, 'data' => $decoded, 'http_status' => $apiStatus];
        }

        return ['ok' => true, 'data' => $decoded['Data'] ?? $decoded['data'] ?? $decoded, 'raw' => $decoded];
    }
}
