<?php

namespace Icinga\Module\Perfdatagraphsprometheus\Forms;

use Icinga\Module\Perfdatagraphsprometheus\Client\Prometheus;

use Icinga\Forms\ConfigForm;

use Zend_Validate_Callback;

/**
 * PerfdataGraphsPrometheusConfigForm represents the configuration form for the PerfdataGraphs Prometheus Module.
 * TODO: Icinga Web 2.14 introduced a new Web\Form\ConfigForm, we can migrate when 2.14 is more prevalent
 * Then we can also use ipl Validators.
 */
class PerfdataGraphsPrometheusConfigForm extends ConfigForm
{
    public function init()
    {
        $this->setName('form_config_resource');
        $this->setSubmitLabel($this->translate('Save Changes'));
        $this->setValidatePartial(true);
    }

    public function createElements(array $formData)
    {
        $this->addElement('text', 'prometheus_api_url', [
            'label' => t('Prometheus URL'),
            'description' => t('The URL for Prometheus including the scheme. The API path /api/v1/query_range will the appended to the given URL'),
            'required' => true,
            'placeholder' => 'http://localhost:9090',
        ]);

        $this->addElement('select', 'prometheus_api_auth_method', [
            'label' => 'API authentication method',
            'description' => 'Authentication method to use for the API',
            'multiOptions' => [
                'none' => t('None'),
                'basic' => 'Basic Auth',
                'token' => 'Token',
            ],
            'class' => 'autosubmit',
            'required' => false,
        ]);

        if (isset($formData['prometheus_api_auth_method']) && $formData['prometheus_api_auth_method'] === 'basic') {
            $this->addElement('text', 'prometheus_api_auth_username', [
                'label' => t('HTTP basic auth username'),
                'description' => t('The user for HTTP basic auth'),
                'required' => true,
            ]);

            $this->addElement('password', 'prometheus_api_auth_password', [
                'label' => t('HTTP basic auth password'),
                'description' => t('The password for HTTP basic auth'),
                'renderPassword' => true,
                'required' => true,
            ]);
        }

        if (isset($formData['prometheus_api_auth_method']) && $formData['prometheus_api_auth_method'] === 'token') {
            $this->addElement('text', 'prometheus_api_auth_tokentype', [
                'label' => t('Token type for the Authorization header'),
                'description' => t('API Token type for the Authorization header (default: Bearer)'),
                'value' => 'Bearer',
            ]);

            $this->addElement('password', 'prometheus_api_auth_tokenvalue', [
                'label' => t('Token for the Authorization header'),
                'description' => t('API Token for the Authorization header'),
                'renderPassword' => true,
                'required' => true,
            ]);
        }

        $this->addElement('checkbox', 'prometheus_api_auth_mtls', [
            'label' => t('Use client certificate (mTLS)'),
            'description' => t('Use client certificate (mTLS) for the connection'),
            'class' => 'autosubmit',
        ]);

        if (isset($formData['prometheus_api_auth_mtls']) && $formData['prometheus_api_auth_mtls'] === '1') {
            $this->addElement('text', 'prometheus_api_auth_mtls_cert', [
                'label' => t('mTLS client certificate path'),
                'description' => t('Path to the client certificate'),
                'required' => true,
            ]);
            $this->addElement('text', 'prometheus_api_auth_mtls_key', [
                'label' => t('mTLS client key path'),
                'description' => t('Path to the client key'),
                'required' => true,
            ]);
            $this->addElement('text', 'prometheus_api_auth_mtls_ca', [
                'label' => t('mTLS client CA path'),
                'description' => t('Path to the CA. Defaults to system CA'),
                'required' => false,
            ]);
        }

        $this->addElement('number', 'prometheus_api_timeout', [
            'label' => t('HTTP timeout in seconds'),
            'description' => t('HTTP timeout for the API in seconds. Should be higher than 0'),
            'placeholder' => 10,
        ]);


        $this->addElement('checkbox', 'prometheus_api_tls_insecure', [
            'description' => t('Skip the TLS verification'),
            'label' => t('Skip the TLS verification')
        ]);

        $greaterThanValidator = new Zend_Validate_Callback(function ($value) {
            if ($value <= 0) {
                return false;
            }
            return true;
        });

        $greaterThanValidator->setMessage(
            $this->translate('The cannot be smaller than 1'),
            Zend_Validate_Callback::INVALID_VALUE
        );

        $this->addElement('number', 'prometheus_api_max_data_points', [
            'label' => t('The maximum numbers of datapoints each series returns'),
            'description' => t(' '),
            'description'   => t(
                'The maximum numbers of datapoints each series returns.'
                    . ' The module will use the step parameter to downsample to this number.'
            ),
            'required' => false,
            'placeholder' => 10000,
            'validators' => [$greaterThanValidator],
        ]);
    }

    public function addSubmitButton()
    {
        parent::addSubmitButton()
            ->getElement('btn_submit')
            ->setDecorators(['ViewHelper']);

        $this->addElement(
            'submit',
            'resource_validation',
            [
                'ignore' => true,
                'label' => $this->translate('Validate Configuration'),
                'data-progress-label' => $this->translate('Validation In Progress'),
                'decorators' => ['ViewHelper']
            ]
        );

        $this->setAttrib('data-progress-element', 'resource-progress');
        $this->addElement(
            'note',
            'resource-progress',
            [
                'decorators' => [
                    'ViewHelper',
                    ['Spinner', ['id' => 'resource-progress']]
                ]
            ]
        );

        $this->addDisplayGroup(
            ['btn_submit', 'resource_validation', 'resource-progress'],
            'submit_validation',
            [
                'decorators' => [
                    'FormElements',
                    ['HtmlTag', ['tag' => 'div', 'class' => 'control-group form-controls']]
                ]
            ]
        );

        return $this;
    }

    public function isValidPartial(array $formData)
    {
        if ($this->getElement('resource_validation')->isChecked() && parent::isValid($formData)) {
            $validation = static::validateFormData($this);
            if ($validation !== null) {
                $this->addElement(
                    'note',
                    'inspection_output',
                    [
                        'order' => 0,
                        'value' => '<strong>' . $this->translate('Validation Log') . "</strong>\n\n"
                            . $validation['output'] ?? '',
                        'decorators' => [
                            'ViewHelper',
                            ['HtmlTag', ['tag' => 'pre', 'class' => 'log-output']],
                        ]
                    ]
                );

                if (isset($validation['error'])) {
                    $this->warning(sprintf(
                        $this->translate('Failed to successfully validate the configuration: %s'),
                        $validation['error']
                    ));
                    return false;
                }
            }

            $this->info($this->translate('The configuration has been successfully validated.'));
        }

        return true;
    }

    public static function validateFormData($form)
    {
        $baseURI = $form->getValue('prometheus_api_url', 'http://localhost:9090');
        $timeout = (int) $form->getValue('prometheus_api_timeout', 10);
        // Hint: We use a "skip TLS" logic in the UI, but Guzzle uses "verify TLS"
        $tlsVerify = !(bool) $form->getValue('prometheus_api_tls_insecure', false);
        $maxDataPoints = (int) $form->getValue('prometheus_api_max_data_points', 10000);
        // Auth values
        $authMethod = $form->getValue('prometheus_api_auth_method', 'none');
        $authTokenType = $form->getValue('prometheus_api_auth_tokentype', 'Bearer');
        $authTokenValue = $form->getValue('prometheus_api_auth_tokenvalue', '');
        $authUsername = $form->getValue('prometheus_api_auth_username', '');
        $authPassword = $form->getValue('prometheus_api_auth_password', '');
        // mTLS values
        $authMTLS = $form->getValue('prometheus_api_auth_mtls', false);
        $authMTLSCert = $form->getValue('prometheus_api_auth_mtls_cert', '');
        $authMTLSKey = $form->getValue('prometheus_api_auth_mtls_key', '');
        $authMTLSCA = $form->getValue('prometheus_api_auth_mtls_ca', '');

        $auth = [
            'method' => mb_strtolower($authMethod),
            'tokentype' => $authTokenType,
            'tokenvalue' => $authTokenValue,
            'username' => $authUsername,
            'password' => $authPassword,
            'mtls' => $authMTLS,
            'mtls_cert' => $authMTLSCert,
            'mtls_key' => $authMTLSKey,
            'mtls_ca' => $authMTLSCA,
        ];

        try {
            $c = new Prometheus(
                baseURI: $baseURI,
                timeout: $timeout,
                maxDataPoints: $maxDataPoints,
                tlsVerify: $tlsVerify,
                auth: $auth,
            );
        } catch (Exception $e) {
            return ['output' => 'General error: ' . $e->getMessage(), 'error' => true];
        }

        $status = $c->status();

        return $status;
    }
}
