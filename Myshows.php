<?php

$myshows = new Myshows();
$myshows->rateEpisodeFromFile();

class Myshows
{
    protected $cookies = [];
    protected $token;
    protected $login;

    public function __construct()
    {
        if (file_exists('backup.txt')) {
            $auth = unserialize(file_get_contents('backup.txt'), ['allowed_classes' => ['Myshows']]);
            $this->cookies = $auth->cookies;
            $this->token = $auth->token;
            $this->login = $auth->login;
        } else {
            $login = 'bmlraXRvaXpvQG1haWwucnU=';
            $password = 'NWI1NGY1NWJiZmFmNjEwMmE2M2UyY2VlZmM5MjlkN2E=';
            $this->authorize($login, $password);
        }
    }

    /** Авторизация
     * @param string $login
     * @param string $password
     * @return bool|string
     */
    public function authorize($login, $password)
    {
        $login = base64_decode($login);
        $password = base64_decode($password);
        try {
            $this->getAuthorizationData();
            $requestData['params'] = [
                'login' => $login,
                'password' => $password,
            ];
            $result = $this->apiRequest('LoginSiteUser', $requestData);
            $this->setCookiesFromHtml($result);
            return true;
        } catch (Throwable $e) {
            echo $e->getMessage();
            return false;
        }
    }

    /** Получение первычных данных для авторизации
     * @return array|bool
     */
    public function getAuthorizationData()
    {
        if ($this->getToken() && $this->getCookies()) {
            return true;
        }
        try {
            $html = $this->apiRequest('', null, 'https://myshows.me');
            if (!$this->setTokenFromHtml($html) || !$this->setCookiesFromHtml($html)) {
                throw new Exception('Не удалось получить данные для авторизации');
            }
            $this->serializeClass();
            return true;
        } catch (Throwable $e) {
            echo $e->getMessage();
            return false;
        }
    }

    public function getToken()
    {
        return $this->token;
    }

    public function setToken(string $token)
    {
        $this->token = $token;
        return $this->token;
    }

    public function getCookies()
    {
        return $this->cookies;
    }

    public function setCookies(array $cookies)
    {
        $this->cookies = array_merge($cookies, $this->getCookies());
        return $this->cookies;
    }

    /** Запрос к Api
     * @param string $action
     * @param null|array $params
     * @param null|string $customUrl
     * @return bool|string
     * @throws Exception
     */
    public function apiRequest($action = '', $params = null, $customUrl = null)
    {
        $url = $customUrl ?? 'https://myshows.me/rpc/?tm=' . time();
        $requestData = [
            'jsonrpc' => '2.0',
            'method' => $action,
            'id' => 1,
            'params' => $params['params']
        ];
        $requestData['params']['__token'] = $this->getToken();
        $requestData = json_encode($requestData, JSON_THROW_ON_ERROR, 512);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        //curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        //curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestData);
        //curl_setopt($ch, CURLOPT_PROXY, "localhost:55116");
        if ($customUrl || $action === 'LoginSiteUser') {
            curl_setopt($ch, CURLOPT_HEADER, 1);
        }

        $headers = array();
        $headers[] = 'Accept: application/json, text/javascript, */*; q=0.01';
        $headers[] = 'X-Requested-With: XMLHttpRequest';
        $headers[] = 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/80.0.3987.163 Safari/537.36';
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Origin: https://myshows.me';
        $headers[] = 'Referer: https://myshows.me/';
        $headers[] = 'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7';
        if ($cookies = $this->getCookies()) {
            foreach ($cookies as $cookie) {
                $headers[] = 'Cookie: ' . $cookie;
            }
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new Exception('Не удалось выполнить запрос: ' . curl_error($ch));
        }
        curl_close($ch);
        if ($customUrl || $action === 'LoginSiteUser') {
            $response = json_decode($result);
            if (isset($response['error'])) {
                if ($response['data'] === 'Invalid Token') {
                    $this->token = null;
                    $this->cookies = [];
                    $this->getAuthorizationData();
                    $this->apiRequest($action, $params, $customUrl);
                }
                throw new Exception('Ошибки запроса: ' . json_encode($response['error']));
            }
        }
        return $result;
    }

    /** Получаем токен из html
     * @param $html
     * @return bool
     */
    public function setTokenFromHtml($html): bool
    {
        $regex = '/(var __token = \')(.*)(\';)/m';
        preg_match($regex, $html, $matches);
        $token = $matches[2];
        if (!$token) {
            return false;
        }
        $this->setToken($token);
        return true;
    }

    /** Получаем куки из html
     * @param $html
     * @return bool
     */
    public function setCookiesFromHtml($html): bool
    {
        preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $html, $matches);
        if (!$matches) {
            return false;
        }
        $cookies = $matches[1];
        foreach ($this->getCookies() as $cookie) {
            if (strpos($cookie, 'login')) {
                $login = explode('=', $cookie)[1];
                $this->setLogin($login);
                break;
            }
        }
        $this->setCookies($cookies);
        return true;
    }

    /** Сериализируем класс после каждого изменения
     *
     */
    public function serializeClass()
    {
        file_put_contents('backup.txt', serialize($this));
    }

    /** Отметить эпизод из файла
     * @param string $filePath
     */
    public function rateEpisodeFromFile($filePath = 'episodes.txt')
    {
        try {
            if (!file_exists($filePath)) {
                $this->setEpisodeListFromUrl();
            }
            $episodes = file($filePath);
            $episode = trim($episodes[0]);
            if (!$episode) {
                throw new Exception('Не удалось получить ID эпизода (закончились серии или некорректный файл)');
            }
            if ($this->rateEpisode($episode)) {
                unset($episodes[0]);
                file_put_contents('episodes.txt', implode('', $episodes));
            }
        } catch (Throwable $e) {
            echo 'Не удалось отметить серию из заданного фалйа: ' . $e->getMessage();
        }
    }

    /** Получаем список серий для отметок и сохраняем в файл
     * @param string $url
     * @return bool
     * @throws Exception
     */
    public function setEpisodeListFromUrl($url = 'https://myshows.me/profile/')
    {
        try {
            $html = $this->apiRequest('', null, $url);
            $regex = '/(href="https:\/\/myshows.me\/view\/episode\/)(.*)(\/")/';
            preg_match_all($regex, $html, $matches);
            if (!$matches || !count($matches)) {
                return false;
            }
            array_unique($matches[2]);
            $episodes = implode("\n", $matches[2]);
            file_put_contents('episodes.txt', $episodes);
            return true;
        } catch (Throwable $e) {
            echo 'Не удалось сохранить список серий: ' . $e->getMessage();
            return false;
        }
    }

    /** Отмечаем эпизод просмотренным
     * @param null|string $episode
     * @return bool
     */
    public function rateEpisode($episode = null)
    {
        try {
            if (!$episode) {
                $this->rateEpisodeFromFile();
            }
            $requestData['params'] = [
                'episodeId' => (int)$episode,
                'rating' => 5,
            ];
            $this->apiRequest('RateEpisode', $requestData);
            if (!$this->validateRated($episode)) {
                throw new Exception('запрос ушел, а эпизод не отмечен :(');
            }
            return true;
        } catch (Exception $e) {
            echo('Не удалось отметить эпизод: ' . $e->getMessage());
            return false;
        }
    }

    /** Валидируем удалось ли отметить серию
     * @param string $episode
     * @return bool
     * @throws Exception
     */
    public function validateRated($episode = null)
    {
        if (!$this->getLogin()) {
            $this->getAuthorizationData();
        }

        $html = $this->apiRequest('', null, 'https://myshows.me/' . $this->getLogin());
        if ($episode) {
            if (!strpos($html, "https://myshows.me/view/episode/$episode")) {
                if (!strpos($html, '<b>Сегодня</b>')) {
                    throw new Exception('Не удалось найти эпизод: обнаружены просмотры за сегодня');
                }
            }
        } else {
            if (!strpos($html, '<b>Сегодня</b>')) {
                throw new Exception('Не обнаружены просмотры за сегодня');
            }
        }
        return true;
    }

    public function getLogin()
    {
        return $this->token;
    }

    public function setLogin(string $login): string
    {
        $this->login = $login;
        return $this->login;
    }
}