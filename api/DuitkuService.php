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

        $productDetails = (string) ($params['productDetails'] ?? 'Subscription Payment');
        $email = (string) ($params['email'] ?? '');
        $phoneNumber = (string) ($params['phoneNumber'] ?? '');
        $customerVaName = (string) ($params['customerVaName'] ?? 'Customer');
        $callbackUrl = (string) ($params['callbackUrl'] ?? '');
        $returnUrl = (string) ($params['returnUrl'] ?? '');
        $expiryPeriod = (int) ($params['expiryPeriod'] ?? 1440);

        $popBody = [
            'paymentAmount'   => $paymentAmount,
            'merchantOrderId' => $merchantOrderId,
            'productDetails'  => $productDetails,
            'customerVaName'  => $customerVaName,
            'email'           => $email,
            'phoneNumber'     => $phoneNumber,
            'callbackUrl'     => $callbackUrl,
            'returnUrl'       => $returnUrl,
            'expiryPeriod'    => $expiryPeriod,
        ];
        if (!empty($params['itemDetails']) && is_array($params['itemDetails'])) {
            $popBody['itemDetails'] = $params['itemDetails'];
        }
        if (!empty($params['customerDetail']) && is_array($params['customerDetail'])) {
            $popBody['customerDetail'] = $params['customerDetail'];
        }

        // Primary path: official POP endpoint with x-duitku headers.
        $result = $this->postPopCreateInvoice($popBody);

        // Fallback path: API v2 inquiry for compatibility with older account setups.
        if (!$result['ok']) {
            $apiBody = $popBody;
            $apiBody['merchantCode'] = $this->merchantCode;
            $apiBody['signature'] = md5($this->merchantCode . $merchantOrderId . $paymentAmount . $this->apiKey);
            $fallback = $this->post('/v2/inquiry', $apiBody);
            if ($fallback['ok']) {
                $result = $fallback;
            } else {
                $fallback['error'] = ($result['error'] ?? 'POP create invoice failed') . ' | API fallback failed: ' . ($fallback['error'] ?? 'unknown');
                return $fallback;
            }
        }

        $data = $result['data'] ?? [];
        return [
            'ok' => true,
            'payment_url' => (string) ($data['paymentUrl'] ?? ''),
            'reference' => (string) ($data['reference'] ?? ''),
            'raw' => $data,
        ];
    }

    private function postPopCreateInvoice(array $body): array
    {
        $timestamp = (string) round(microtime(true) * 1000);
        $signature = hash('sha256', $this->merchantCode . $timestamp . $this->apiKey);

        $base = $this->resolvePopBaseUrl();
        $url = rtrim($base, '/') . '/api/merchant/createInvoice';

        $headers = [
            'x-duitku-signature: ' . $signature,
            'x-duitku-timestamp: ' . $timestamp,
            'x-duitku-merchantcode: ' . $this->merchantCode,
        ];

        return $this->postAbsoluteUrl($url, $body, $headers);
    }

    private function resolvePopBaseUrl(): string
    {
        $base = strtolower($this->baseUrl);

        if (str_contains($base, 'api-prod.duitku.com') || str_contains($base, 'passport.duitku.com')) {
            return 'https://api-prod.duitku.com';
        }
        if (str_contains($base, 'api-sandbox.duitku.com') || str_contains($base, 'sandbox.duitku.com')) {
            return 'https://api-sandbox.duitku.com';
        }

        return 'https://api-sandbox.duitku.com';
    }

    private function post(string $path, array $body): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        return $this->postAbsoluteUrl($url, $body);
    }

    private function postAbsoluteUrl(string $url, array $body, array $extraHeaders = []): array
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ['ok' => false, 'error' => 'Gagal encode JSON request.'];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'Gagal inisialisasi cURL.'];
        }

        $headers = array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
            'Content-Length: ' . strlen($json),
        ], $extraHeaders);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $raw = curl_exec($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'error' => 'cURL error: ' . $err, 'http_status' => $httpStatus];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            $snippet = trim(substr((string) $raw, 0, 240));
            return [
                'ok' => false,
                'error' => 'Invalid JSON response dari Duitku.',
                'http_status' => $httpStatus,
                'raw_response' => $snippet,
            ];
        }

        $statusCode = (string) ($decoded['statusCode'] ?? '');
        if ($httpStatus >= 400 || ($statusCode !== '' && $statusCode !== '00')) {
            $msg = (string) ($decoded['statusMessage'] ?? $decoded['message'] ?? ('HTTP ' . $httpStatus));
            return [
                'ok' => false,
                'error' => $msg,
                'data' => $decoded,
                'http_status' => $httpStatus,
            ];
        }

        return ['ok' => true, 'data' => $decoded, 'raw' => $decoded];
    }
}
