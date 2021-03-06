<?php

namespace wdmg\stats\behaviors;

use wdmg\stats\models\Robots;
use wdmg\stats\models\Visitors;
use Yii;
use yii\base\Behavior;
use yii\base\Event;
use yii\web\Controller;
use yii\web\Cookie;
use yii\web\Request;
use yii\helpers\Json;

class ControllerBehavior extends \yii\base\Behavior
{

    public function events()
    {
        return [
            Controller::EVENT_AFTER_ACTION => 'onAfterAction'
        ];
    }
    /**
     * @param $event Event
     * @throws \yii\base\Exception
     */
    public function onAfterAction($event)
    {

        $module = Yii::$app->getModule('stats');
        if (($module->ignoreDev && (YII_DEBUG || YII_ENV == 'dev')) || ($module->ignoreAjax && Yii::$app->request->isAjax)) {
            return;
        }

        // Get request instance
        $request = Yii::$app->request;

        // Ignoring by route
        if (count($module->ignoreRoute) > 0) {
            foreach ($module->ignoreRoute as $route) {
                if(preg_match('/('.preg_quote($route,'/').')/i', $request->url) || preg_match('/('.preg_quote($route,'/').')/i', $request->url))
                    return;
            }
        }

        // Ignoring by User IP
        if (count($module->ignoreListIp) > 0) {
            if (in_array($request->userIP, $module->ignoreListIp)) {
                return;
            }
        }

        // Ignoring by User Agent
        if (count($module->ignoreListUA) > 0) {
            foreach($module->ignoreListUA as $user_agent) {

                if(stripos($request->userAgent, $user_agent) !== false)
                    return;

            }
        }

        $cookies = Yii::$app->request->getCookies();

        if (!$cookies->has($module->cookieName)) {
            $cookie = new Cookie();
            $cookie->name = $module->cookieName;
            $cookie->value = Yii::$app->security->generateRandomString();
            $cookie->expire = time() + intval($module->cookieExpire);
            Yii::$app->response->getCookies()->add($cookie);
        } else {
            $cookie = $cookies->get($module->cookieName);
        }

        $visitor = new Visitors();
        $visitor->request_uri = $request->getAbsoluteUrl();
        $visitor->remote_addr = $this->getRemoteIp($request);
        $visitor->remote_host = $this->getRemoteHost($request);
        $visitor->user_id = !Yii::$app->user->isGuest ? Yii::$app->user->identity->id : null;
        $visitor->user_agent = $request->userAgent;
        $visitor->referer_uri = $request->getReferrer();
        $visitor->referer_host = $this->getReferrerHost($request);
        $visitor->https = $request->isSecureConnection ? 1 : 0;
        $visitor->type = $this->identityType($request);
        $visitor->code = Yii::$app->response->statusCode;
        $visitor->session = $cookie->value;
        $visitor->unique = $this->checkUnique($cookie->value);
        $visitor->params = count($request->getQueryParams()) > 0 ? Json::encode($request->getQueryParams()) : null;
        $visitor->robot_id = $this->detectRobot($request->userAgent);
        $visitor->save();

        if($module->storagePeriod !== 0 && rand(1, 10) == 1) {
            $period = (time() - (intval($module->storagePeriod) * 86400));
            $visitor::clearOldStats($period);
        }

    }

    /**
     * Get referrer hostname
     * @param $request Request
     * @return string or null
     */
    public static function getReferrerHost($request)
    {
        return !empty($request->getReferrer()) ? parse_url($request->getReferrer(), PHP_URL_HOST) : null;
    }

    /**
     * Get client IP
     * @param $request Request
     * @return string or null
     */
    public static function getRemoteIp($request)
    {
        $client_ip = $request->userIP;
        if(!$client_ip)
            $client_ip = $request->remoteIP;

        return $client_ip;
    }

    /**
     * Get client hostname
     * @param $request Request
     * @return string or null
     */
    public static function getRemoteHost($request)
    {
        $client_ip = self::getRemoteIp($request);

        $host_name = $request->userHost;
        if(!$host_name)
            $host_name = $request->remoteHost;

        if(!$host_name)
            $host_name = gethostbyaddr($client_ip);

        return $host_name;
    }

    /**
     * Is unique visitor
     * @param $session value
     * @return integer
     */
    public static function checkUnique($session)
    {
        $count = Visitors::find()->where([
            'session'=> $session
        ])->count();

        if($count > 0)
            return 0;
        else
            return 1;
    }

    /**
     * Detect bots
     * @param $user_agent
     * @return integer
     */
    public static function detectRobot($user_agent, $cache_timeout = 3600)
    {
        $db = Robots::getDb();
        $robots = $db->cache(function ($db) {
            return Robots::find()->asArray()->all();
        }, $cache_timeout);

        if (count($robots) > 0) {
            foreach ($robots as $robot) {
                if (!empty($robot["regexp"]) && preg_match("/".preg_quote($robot["regexp"], "/")."/i", $user_agent)) {
                    return $robot["id"];
                }
            }
        }

        return null;
    }

    /**
     * Determine the type of user
     * @param $request Request
     * @return int
     */
    public static function identityType($request)
    {

        $module = Yii::$app->getModule('stats');

        if(preg_match('/(?!&)utm_([a-z0-9=%]+)/i', $request->getReferrer()) || preg_match('/(?!&)utm_([a-z0-9=%]+)/i', $request->getUrl()))
            return Visitors::TYPE_FROM_ADVERTS;

        if (count($module->advertisingSystems) > 0) {
            $patterns = implode($module->advertisingSystems, "|");
            if(preg_match('/('.$patterns.')/i', $request->getReferrer()) || preg_match('/('.$patterns.')/i', $request->getUrl()))
                return Visitors::TYPE_FROM_ADVERTS;
            else
                $patterns = '';
        }

        if ($request->getReferrer() === null)
            return Visitors::TYPE_DERECT_ENTRY;
        else if (preg_match("($request->hostName)", $request->getReferrer()))
            return Visitors::TYPE_INNER_VISIT;

        if (count($module->searchEngines) > 0) {
            $patterns = implode($module->searchEngines, "|");
            if(preg_match('/('.$patterns.')/i', $request->getReferrer()))
                return Visitors::TYPE_FROM_SEARCH;
            else
                $patterns = '';
        }

        if (count($module->socialNetworks) > 0) {
            $patterns = implode($module->socialNetworks, "|");
            if(preg_match('/('.$patterns.')/i', $request->getReferrer()))
                return Visitors::TYPE_FROM_SOCIALS;
            else
                $patterns = '';
        }

        return Visitors::TYPE_UNDEFINED;
    }

}