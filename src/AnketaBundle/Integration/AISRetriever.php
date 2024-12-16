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

    public function getResult($fakulta = null, array $semestre = null) {
        $input = $this->getConnectionData();
        $input['fakulta'] = $fakulta;
        $input['semestre'] = $semestre;

        return $this->runVotr($input);
    }

    private function getConnectionData() {
        $server = array(
            'login_types' => array('saml_andrvotr', 'cookie'),
            'ais_cookie' => 'JSESSIONID',
            'ais_url' => 'https://ais2.uniba.sk/',
            'rest_cookie' => 'JSESSIONID',
            'rest_url' => 'https://votr-api.uniba.sk/',
        );

        $info = $this->loginInfo;

        if (!empty($info['my_entity_id']) && !empty($info['andrvotr_api_key'])) {
            if (empty($_SERVER['ANDRVOTR_AUTHORITY_TOKEN'])) {
                throw new \Exception("ANDRVOTR_AUTHORITY_TOKEN is not set");
            }
            $params = array(
                'type' => 'saml_andrvotr',
                'my_entity_id' => $info['my_entity_id'],
                'andrvotr_api_key' => $info['andrvotr_api_key'],
                'andrvotr_authority_token' => $_SERVER['ANDRVOTR_AUTHORITY_TOKEN'],
            );
        } else if (!empty($info['ais_cookie'])) {
            $params = array(
                'type' => 'cookie',
                'ais_cookie' => $info['ais_cookie'],
            );

            if (!empty($info['rest_cookie'])) {
                $params['rest_cookie'] = $info['rest_cookie'];
            } else {
                unset($server['rest_cookie']);
                unset($server['rest_url']);
            }
        } else {
            throw new \Exception("Neither my_entity_id+andrvotr_api_key nor ais_cookie is present");
        }

        return array(
            'server' => $server,
            'params' => $params,
        );
    }

    private function runVotr($input) {
        $pythonPath = __DIR__ . '/../../../vendor/svt/votr/.venv/bin/python';
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
