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
        $paymentMethod = strtoupper(trim((string) ($params['paymentMethod'] ?? '')));

        if ($merchantOrderId === '' || $paymentAmount <= 0 || $paymentMethod === '') {
            return ['ok' => false, 'error' => 'merchantOrderId/paymentAmount/paymentMethod tidak valid.'];
        }

        $productDetails = (string) ($params['productDetails'] ?? 'Subscription Payment');
        $email = (string) ($params['email'] ?? '');
        $phoneNumber = (string) ($params['phoneNumber'] ?? '');
        $customerVaName = (string) ($params['customerVaName'] ?? 'Customer');
        $callbackUrl = (string) ($params['callbackUrl'] ?? '');
        $returnUrl = (string) ($params['returnUrl'] ?? '');
        $expiryPeriod = (int) ($params['expiryPeriod'] ?? 1440);

        $body = [
            'merchantCode'    => $this->merchantCode,
            'paymentAmount'   => $paymentAmount,
            'paymentMethod'   => $paymentMethod,
            'merchantOrderId' => $merchantOrderId,
            'productDetails'  => $productDetails,
            'customerVaName'  => $customerVaName,
            'email'           => $email,
            'phoneNumber'     => $phoneNumber,
            'callbackUrl'     => $callbackUrl,
            'returnUrl'       => $returnUrl,
            'expiryPeriod'    => $expiryPeriod,
            'signature'       => md5($this->merchantCode . $merchantOrderId . $paymentAmount . $this->apiKey),
        ];
        if (!empty($params['itemDetails']) && is_array($params['itemDetails'])) {
            $body['itemDetails'] = $params['itemDetails'];
        }
        if (!empty($params['customerDetail']) && is_array($params['customerDetail'])) {
            $body['customerDetail'] = $params['customerDetail'];
        }

        $result = $this->post('/v2/inquiry', $body);
        if (!$result['ok']) return $result;

        $data = $result['data'] ?? [];
        return [
            'ok' => true,
            'payment_url' => (string) ($data['paymentUrl'] ?? ''),
            'reference' => (string) ($data['reference'] ?? ''),
            'raw' => $data,
        ];
    }

    private function resolveV2BaseUrl(): string
    {
        $base = strtolower($this->baseUrl);

        if (str_contains($base, 'passport.duitku.com') || str_contains($base, 'api-prod.duitku.com')) {
            return 'https://passport.duitku.com/webapi/api/merchant';
        }
        if (str_contains($base, 'sandbox.duitku.com') || str_contains($base, 'api-sandbox.duitku.com')) {
            return 'https://sandbox.duitku.com/webapi/api/merchant';
        }
        if (str_contains($base, '/webapi/api/merchant')) return $this->baseUrl;

        return 'https://sandbox.duitku.com/webapi/api/merchant';
    }

    private function post(string $path, array $body): array
    {
        $url = rtrim($this->resolveV2BaseUrl(), '/') . '/' . ltrim($path, '/');
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

        $statusCode = (string) ($decoded['statusCode'] ?? $decoded['responseCode'] ?? '');
        if ($httpStatus >= 400 || ($statusCode !== '' && $statusCode !== '00')) {
            $msg = (string) (
                $decoded['statusMessage']
                ?? $decoded['responseMessage']
                ?? $decoded['Message']
                ?? $decoded['message']
                ?? ('HTTP ' . $httpStatus)
            );
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
