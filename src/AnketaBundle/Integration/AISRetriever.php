<?php
/**
 * This file contains user provider for Anketa
 *
 * @copyright Copyright (c) 2011 The FMFI Anketa authors (see AUTHORS).
 * Use of this source code is governed by a license that can be
 * found in the LICENSE file in the project root directory.
 *
 * @package    Anketa
 * @subpackage Security
 * @author     Martin Sucha <anty.sk+svt@gmail.com>
 */

namespace AnketaBundle\Integration;

use Symfony\Component\Process\ProcessBuilder;

class AISRetriever
{

    /** @var array|null */
    private $loginInfo;

    public function __construct($loginInfo)
    {
        $this->loginInfo = $loginInfo;
    }

    public function getResult(array $semestre = null) {
        $input = $this->getConnectionData();
        $input['semestre'] = $semestre;

        return $this->runVotr($input);
    }

    private function getConnectionData() {
        $server = array(
            'login_types' => array('cosignproxy', 'cosigncookie'),
            'ais_cookie' => 'cosign-filter-ais2.uniba.sk',
            'ais_url' => 'https://ais2.uniba.sk/',
            'rest_cookie' => 'cosign-filter-votr-api.uniba.sk',
            'rest_url' => 'https://votr-api.uniba.sk/',
        );

        $info = $this->loginInfo;

        if (!empty($info['cosign_proxy'])) {
            if (empty($_SERVER['COSIGN_SERVICE'])) {
                throw new \Exception("COSIGN_SERVICE is not set");
            }
            $name = $_SERVER['COSIGN_SERVICE'];
            $php_name = strtr($name, '.', '_');
            $value = $_COOKIES[$php_name];
            $value = strtr($value, ' ', '+');

            $params = array(
                'type' => 'cosignproxy',
                'cosign_proxy' => $info['cosign_proxy'],
                'cosign_service' => array($name, $value),
            );
        } else if (!empty($info['cosign_cookie'])) {
            $params = array(
                'type' => 'cosigncookie',
                'ais_cookie' => $info['cosign_cookie'],
            );

            if (!empty($info['rest_cookie'])) {
                $params['rest_cookie'] = $info['rest_cookie'];
            } else {
                unset($server['rest_cookie']);
                unset($server['rest_url']);
            }
        } else {
            throw new \Exception("Neither cosign_proxy nor cosign_cookie is present");
        }

        return array(
            'server' => $server,
            'params' => $params,
        );
    }

    private function runVotr($input) {
        $pythonPath = __DIR__ . '/../../../vendor/svt/votr/venv/bin/python';
        $runnerPath = __DIR__ . '/votr_runner.py';

        $pb = new ProcessBuilder(array($pythonPath, $runnerPath));
        $pb->setInput(json_encode($input));
        $process = $pb->getProcess();
        if ($process->run() != 0) {
            throw new \Exception("Votr runner failed:\n" . $process->getErrorOutput());
        }
        return json_decode($process->getOutput(), true);
    }

}
