<?php


namespace Hitrov\Notification;


use Hitrov\Exception\ApiCallException;
use Hitrov\Exception\CurlException;
use Hitrov\Exception\NotificationException;
use Hitrov\HttpClient;
use Hitrov\Interfaces\NotifierInterface;
use JsonException;

class Telegram implements NotifierInterface
{
    /**
     * @param string $message
     * @return array
     * @throws ApiCallException|CurlException|JsonException|NotificationException
     */
    public function notify(string $message): array
    {
        $apiKey = $this->getApiKey();
        $telegramUserId = getenv('TELEGRAM_USER_ID');

        $body = http_build_query([
            'text' => $message,
            'chat_id' => $telegramUserId,
        ]);

        $curlOptions = [
            CURLOPT_URL => "https://api.telegram.org/bot$apiKey/sendMessage",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_MAXREDIRS => 1,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $body,
        ];

        return HttpClient::getResponse($curlOptions);
    }

    public function isSupported(): bool
    {
        return $this->getApiKey() !== '' && !empty(getenv('TELEGRAM_USER_ID'));
    }

    private function getApiKey(): string
    {
        $file = getenv('TELEGRAM_BOT_TOKEN_FILE');
        if ($file !== false && $file !== '') {
            $token = file_get_contents($file);
            if ($token === false) {
                throw new \RuntimeException('Cannot read Telegram token file');
            }
            return trim($token);
        }

        return (string) getenv('TELEGRAM_BOT_API_KEY');
    }
}
