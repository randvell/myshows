<?php

// Скрипт для ежедневного отмечания серий на Myshows
$myshows = new Myshows();
if (!$myshows->validateRated()) {
    $myshows->rateEpisode();
}

class Myshows
{
    protected $cookies = [];
    protected $token;
    protected $login;

    public function __construct()
    {
        $this->log('Инициализация класса');
        if (file_exists('backup.txt')) {
            $auth = unserialize(file_get_contents('backup.txt'), ['allowed_classes' => ['Myshows']]);
            $this->cookies = $auth->cookies;
            $this->token = $auth->token;
            $this->login = $auth->login;
        } else {
            $this->authorize();
        }
    }

    public function getLogin()
    {
        return $this->login;
    }

    public function setLogin($login): string
    {
        $this->login = $login;
        $this->serializeClass();
        return $this->login;
    }

    public function getToken()
    {
        return $this->token;
    }

    public function setToken(string $token)
    {
        $this->token = $token;
        $this->serializeClass();
        return $this->token;
    }

    public function getCookies()
    {
        return $this->cookies;
    }

    public function setCookies(array $cookies)
    {
        $this->cookies = array_merge($cookies, $this->getCookies());
        $this->serializeClass();
        return $this->cookies;
    }

    /** Авторизация
     * @param string $login
     * @param string $password
     * @return bool|string
     */
    public function authorize($login = null, $password = null)
    {
        $this->log('Производится авторизация');
        if (!$login && !$password) {
            $authData = $this->getAuthData();
            $login = $authData['login'];
            $password = $authData['password'];
        }
        $login = base64_decode($login);
        $password = base64_decode($password);
        try {
            $this->setAuthorizationData();
            $requestData['params'] = [
                'login' => $login,
                'password' => $password,
            ];
            $result = $this->apiRequest('LoginSiteUser', $requestData);
            $this->setCookiesFromHtml($result);
            return true;
        } catch (Throwable $e) {
            $this->log('Не удалось авторизоваться: ' . $e->getMessage());
            exit();
        }
    }

    /** Получаем данные для авторизации из файла
     * @return array
     */
    public function getAuthData()
    {
        $data = explode("\r\n", file_get_contents('auth.txt'));
        return ['login' => $data[0], 'password' => $data[1]];
    }

    /** Получение первычных данных для авторизации
     * @return array|bool
     */
    public function setAuthorizationData()
    {
        $this->log('Запрашиваю данные для авторизации');
        if ($this->getToken() && $this->getCookies()) {
            $this->log('Данные для авторизации имеются в конфиге');
            return true;
        }
        try {
            $html = $this->apiRequest('', null, 'https://myshows.me/');
            if (!$this->setTokenFromHtml($html) || !$this->setCookiesFromHtml($html)) {
                throw new Exception('Не удалось получить данные для авторизации');
            }
            return true;
        } catch (Throwable $e) {
            $this->log($e->getMessage());
            return false;
        }
    }

    /** Логгирование всего и вся
     * @param $message
     */
    public function log($message)
    {
        echo $message . '<br>';
        file_put_contents('log.txt', date('d M Y H:i:s ') . $message . "\n", FILE_APPEND);
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

        $this->log('Выполняю запрос к ' . $url . ($action ? (' action: ' . $action) : '') .
            (($params && ($action !== 'LoginSiteUser')) ? (' params: ' . json_encode($params)) : ''));
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new Exception('Не удалось выполнить запрос: ' . curl_error($ch));
        }
        curl_close($ch);
        if ($customUrl || $action === 'LoginSiteUser') {
            if ($action !== 'LoginSiteUser') {
                $response = json_decode($result, true);
            } else {
                $response = json_encode(explode("\r\n\r\n", $result)[1]);
            }
            if (isset($response['error'])) {
                if ($response['error']['data'] === 'Invalid Token') {
                    $this->token = null;
                    $this->cookies = [];
                    $this->authorize();
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
        $this->log('Получаю токен из HTML страницы');
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
        $this->log('Получаю кукис и логин из HTML страницы');
        preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $html, $matches);
        if (!$matches) {
            return false;
        }
        $cookies = $matches[1];
        foreach ($cookies as $cookie) {
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
        $this->log('Бэкап данных успешно сохранен');
    }

    /** Отметить эпизод из файла
     * @param string $filePath
     */
    public function rateEpisodeFromFile($filePath = 'episodes.txt')
    {
        $this->log('Отмечаю эпизод из файла');
        try {
            if (!file_exists($filePath)) {
                $this->log('Файл не найден, создаю новый');
                $this->setEpisodeListFromUrl('https://myshows.me/view/231/');
            }
            $episodes = file($filePath);
            $episode = trim($episodes[0]);
            if (!$episode) {
                throw new Exception('Не удалось получить ID эпизода (закончились серии или некорректный файл)');
            }
            $this->rateEpisode($episode);
            unset($episodes[0]);
            file_put_contents('episodes.txt', implode('', $episodes));
            $this->log('Эпизод ' . $episode . ' успешно отмечен');
            //exit();
        } catch (Throwable $e) {
            $this->log('Не удалось отметить серию из заданного фалйа: ' . $e->getMessage());
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
            $this->log('Сохраняю список эпизодов по ссылке ' . $url);
            $html = $this->apiRequest('', null, $url);
            $regex = '/(href="https:\/\/myshows.me\/view\/episode\/)(.*)(\/")/';
            preg_match_all($regex, $html, $matches);
            if (!$matches || !count($matches)) {
                return false;
            }
            array_unique($matches[2]);
            sort($matches[2]);
            $episodes = implode("\n", $matches[2]);
            file_put_contents('episodes.txt', $episodes);
            return true;
        } catch (Throwable $e) {
            $this->log('Не удалось сохранить список серий: ' . $e->getMessage());
            return false;
        }
    }

    /** Отмечаем эпизод просмотренным
     * @param null|string $episode
     * @param bool $retry
     * @return bool
     */
    public function rateEpisode($episode = null, $retry = false)
    {
        $this->log('Приступаю к отметке эпизода');
        try {
            if (!$episode) {
                $this->log('Не передан id эпизода, беру из файла');
                $this->rateEpisodeFromFile();
                return true;
            }
            $requestData['params'] = [
                'episodeId' => (int)$episode,
                'rating' => 5,
            ];
            $this->apiRequest('RateEpisode', $requestData);
            sleep(10);
            if (!$this->validateRated($episode, $retry)) {
                throw new Exception('запрос ушел, а эпизод не отмечен :(');
            }
            return true;
        } catch (Exception $e) {
            $this->log('Не удалось отметить эпизод: ' . $e->getMessage());
            return false;
        }
    }

    /** Валидируем удалось ли отметить серию
     * @param string $episode
     * @param bool $retry
     * @return bool
     */
    public function validateRated($episode = null, $retry = false)
    {
        $this->log('Проверяю отметку о просмотре ' . ($episode ?? 'за сегодня'));
        if (!$this->getLogin()) {
            $this->log('Логин не задан, авторизируюсь заново');
            $this->cookies = [];
            $this->token = '';
            $this->authorize();
        }

        try {
            $html = $this->apiRequest('', null, 'https://myshows.me/' . $this->getLogin());

            $seriesToday = false;
            $dom = new DOMDocument('', 'UTF-8');
            $dom->strictErrorChecking = false;
            @$dom->loadHTML(explode("\r\n\r\n", $html)[1]);
            $xpath = new DomXPath($dom);
            $nodes = $xpath->query('/html/body/div[1]/div/div/main/div');
            foreach ($nodes as $node) {
                if (strpos($node->nodeValue, 'Сегодня') && strpos($node->nodeValue, 'серию сериала')) {
                    $seriesToday = true;
                    break;
                }
            }

            if ($episode) {
                if (!strpos($html, "https://myshows.me/view/episode/$episode")) {
                    $this->log('Не удалось найти информацию о заданном эпизоде');
                    if (!$seriesToday) {
                        $this->log('Просмотры за сегодня также отсутсвуют');
                        if (!$retry) {
                            $this->rateEpisode(null, true);
                        }
                        throw new Exception('Повторная попытка отметить эпизод увенчалась провалом');
                    }
                }
            } else {
                if (!$seriesToday) {
                    $this->log('Просмотры за сегодня отсутсвуют, пытаюсь отметить серию');
                    $this->rateEpisode(null, true);
                } else {
                    $this->log('Отметка за сегодня найдена');
                }
            }
            return true;
        } catch (Throwable $e) {
            $this->log('Не удалось валидировать просмотры за сегодня: ' . $e->getMessage());
            return false;
        }
    }
}