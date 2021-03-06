<?php
/**
 * Shopware 4
 * Copyright © shopware AG
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License with an additional
 * permission and of our proprietary license can be found at and
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "Shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, title and interest in
 * our trademarks remain entirely with us.
 */

/**
 * Shopware InputFilter Plugin
 */
class Shopware_Plugins_Frontend_InputFilter_Bootstrap extends Shopware_Components_Plugin_Bootstrap
{
    public $sqlRegex = 's_core_|s_order_|s_user|benchmark.*\(|(?:insert|replace).+into|update.+set|(?:delete|select).+from|(?:alter|rename|create|drop|truncate).+(?:database|table)|union.+select';
    public $xssRegex = 'javascript:|src\s*=|on[a-z]+\s*=|style\s*=';
    public $rfiRegex = '\.\./|\\0';

    /**
     * Install plugin method
     *
     * @return bool
     */
    public function install()
    {
        $this->subscribeEvent(
            'Enlight_Controller_Front_RouteShutdown',
            'onRouteShutdown',
            -100
        );

        $form = $this->Form();
        $parent = $this->Forms()->findOneBy(array('name' => 'Core'));
        $form->setParent($parent);

        $form->setElement('boolean', 'sql_protection', array('label' => 'SQL-Injection-Schutz aktivieren', 'value' => true));
        $form->setElement('boolean', 'xss_protection', array('label' => 'XSS-Schutz aktivieren', 'value' => true));
        $form->setElement('boolean', 'rfi_protection', array('label' => 'RemoteFileInclusion-Schutz aktivieren', 'value' => true));
        $form->setElement('textarea', 'own_filter', array('label' => 'Eigener Filter', 'value' => null));
        $form->setElement('checkbox', 'refererCheck', array('label' => 'Referer-Check aktivieren', 'value' => 1));

        return true;
    }

    /**
     * Event listener method
     *
     * @param Enlight_Controller_EventArgs $args
     */
    public function onRouteShutdown(Enlight_Controller_EventArgs $args)
    {
        $request = $args->getRequest();
        $front = $args->getSubject();
        $response = $front->Response();
        $config = $this->Config();

        if ($request->getModuleName() == 'backend' || $request->getModuleName() == 'api') {
            return;
        }

        if (!empty($config->refererCheck)
            && $request->isPost()
            && in_array($request->getControllerName(), array('account'))
            && ($referer = $request->getHeader('Referer')) !== null
            && strpos($referer, 'http') === 0
        ) {
            /** @var $shop Shopware_Models_Shop */
            $shop = Shopware()->Shop();
            $validHosts = array(
                $shop->getHost(),
                $shop->getSecureHost()
            );
            $host = parse_url($referer, PHP_URL_HOST);
            $hostWithPort = $host . ':' .parse_url($referer, PHP_URL_PORT);
            if (!in_array($host, $validHosts) && !in_array($hostWithPort, $validHosts)) {
                $response->setException(
                    new Exception('Referer check for frontend session failed')
                );
            }
        }

        $intVars = array('sCategory', 'sContent', 'sCustom');
        foreach ($intVars as $parameter) {
            if (!empty($_GET[$parameter])) {
                $_GET[$parameter] = (int) $_GET[$parameter];
            }
            if (!empty($_POST[$parameter])) {
                $_POST[$parameter] = (int) $_POST[$parameter];
            }
        }


        $regex = array();
        if (!empty($config->sql_protection)) {
            $regex[] = $this->sqlRegex;
        }
        if (!empty($config->xss_protection)) {
            $regex[] = $this->xssRegex;
        }
        if (!empty($config->rfi_protection)) {
            $regex[] = $this->rfiRegex;
        }
        if (!empty($config->own_filter)) {
            $regex[] = $this->own_filter;
        }

        if (empty($regex)) {
            return;
        }

        $regex = '#' . implode('|', $regex) . '#msi';

        $userParams = $request->getUserParams();
        $process = array(
            &$_GET, &$_POST, &$_COOKIE, &$_REQUEST, &$_SERVER, &$userParams
        );
        while (list($key, $val) = each($process)) {
            foreach ($val as $k => $v) {
                unset($process[$key][$k]);
                if (is_array($v)) {
                    $process[$key][self::filterValue($k, $regex)] = $v;
                    $process[] = &$process[$key][self::filterValue($k, $regex)];
                } else {
                    $process[$key][self::filterValue($k, $regex)] = self::filterValue($v, $regex);
                }
            }
        }

        unset($process);
        $request->setParams($userParams);
    }

    /**
     * Filter value by regex
     *
     * @param string $value
     * @param string $regex
     * @return string
     */
    public static function filterValue($value, $regex)
    {
        if (!empty($value)) {
            $value = strip_tags($value);
            if (preg_match($regex, $value)) {
                $value = null;
            }
        }
        return $value;
    }

    /**
     * Returns plugin capabilities
     *
     * @return array
     */
    public function getCapabilities()
    {
        return array(
            'install' => false,
            'enable' => true,
            'update' => true
        );
    }
}
