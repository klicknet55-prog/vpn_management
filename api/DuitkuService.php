<?php
/**
 * Duitku Service
 *
 * Wrapper untuk Duitku API create invoice.
 *
 * Signature create invoice:
 * md5(merchantCode + merchantOrderId + paymentAmount + apiKey)
 */
class DuitkuService
{
    private string $merchantCode;
    private string $apiKey;
    private string $baseUrl;

    public function __construct(string $merchantCode, string $apiKey, string $baseUrl)
    {
        $this->merchantCode = trim($merchantCode);
        $this->apiKey = trim($apiKey);
        $this->baseUrl = rtrim(trim($baseUrl), '/');
    }

    /**
     * Buat invoice pembayaran di Duitku.
     *
     * @param array $params
     * @return array{ok: bool, payment_url?: string, reference?: string, raw?: array, error?: string}
     */
    public function createInvoice(array $params): array
    {
        $merchantOrderId = (string) ($params['merchantOrderId'] ?? '');
        $paymentAmount = (int) ($params['paymentAmount'] ?? 0);

        if ($merchantOrderId === '' || $paymentAmount <= 0) {
            return ['ok' => false, 'error' => 'merchantOrderId/paymentAmount tidak valid.'];
        }

        $body = [
            'merchantCode'    => $this->merchantCode,
            'paymentAmount'   => $paymentAmount,
            'merchantOrderId' => $merchantOrderId,
            'productDetails'  => (string) ($params['productDetails'] ?? ''),
            'email'           => (string) ($params['email'] ?? ''),
            'phoneNumber'     => (string) ($params['phoneNumber'] ?? ''),
            'customerVaName'  => (string) ($params['customerVaName'] ?? ''),
            'callbackUrl'     => (string) ($params['callbackUrl'] ?? ''),
            'returnUrl'       => (string) ($params['returnUrl'] ?? ''),
            'expiryPeriod'    => (int) ($params['expiryPeriod'] ?? 1440),
            'signature'       => md5($this->merchantCode . $merchantOrderId . $paymentAmount . $this->apiKey),
        ];

        if (!empty($params['itemDetails']) && is_array($params['itemDetails'])) {
            $body['itemDetails'] = $params['itemDetails'];
        }

        $result = $this->post('/createInvoice', $body);
        if (!$result['ok']) {
            return $result;
        }

        $data = $result['data'] ?? [];
        return [
            'ok' => true,
            'payment_url' => (string) ($data['paymentUrl'] ?? ''),
            'reference' => (string) ($data['reference'] ?? ''),
            'raw' => $data,
        ];
    }

    private function post(string $path, array $body): array
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ['ok' => false, 'error' => 'Gagal encode JSON request.'];
        }

        $url = $this->baseUrl . '/' . ltrim($path, '/');

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'Gagal inisialisasi cURL.'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $raw = curl_exec($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'error' => 'cURL error: ' . $err];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'Invalid JSON response dari Duitku.'];
        }

        $statusCode = (string) ($decoded['statusCode'] ?? '');
        if ($httpStatus >= 400 || ($statusCode !== '' && $statusCode !== '00')) {
            $msg = (string) ($decoded['statusMessage'] ?? $decoded['message'] ?? ('HTTP ' . $httpStatus));
            return ['ok' => false, 'error' => $msg, 'data' => $decoded];
        }

        return ['ok' => true, 'data' => $decoded, 'raw' => $decoded];
    }
}
