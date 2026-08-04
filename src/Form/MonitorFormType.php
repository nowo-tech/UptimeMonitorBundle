<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Form\Model\MonitorFormData;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractUptimeFormType<MonitorFormData>
 */
#[FormKitConfig('uptime_monitor')]
final class MonitorFormType extends AbstractUptimeFormType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, int> $groupChoices */
        $groupChoices = $options['group_choices'];

        $this->addChoice($builder, 'type', [
            'label'   => 'form.monitor.type',
            'choices' => [
                'form.monitor.type.group' => MonitorType::Group,
                'form.monitor.type.http'  => MonitorType::Http,
                'form.monitor.type.https' => MonitorType::Https,
                'form.monitor.type.tcp'   => MonitorType::Tcp,
                'form.monitor.type.dns'   => MonitorType::Dns,
                'form.monitor.type.ssl'   => MonitorType::Ssl,
                'form.monitor.type.ping'  => MonitorType::Ping,
            ],
        ]);
        $this->addText($builder, 'name', ['label' => 'form.monitor.name']);
        $this->addChoice($builder, 'parentId', [
            'label'       => 'form.monitor.parent',
            'required'    => false,
            'placeholder' => 'form.monitor.parent_none',
            'choices'     => $groupChoices,
            'help'        => 'form.monitor.parent_help',
        ]);
        $this->addText($builder, 'project', [
            'label'    => 'form.monitor.project',
            'required' => false,
            'help'     => 'form.monitor.project_help',
        ]);
        $this->addInteger($builder, 'intervalSeconds', [
            'label' => 'form.monitor.interval',
            'attr'  => ['min' => 30],
        ]);
        $this->addInteger($builder, 'retries', [
            'label'    => 'form.monitor.retries',
            'required' => false,
            'attr'     => ['min' => 0],
            'help'     => 'form.monitor.retries_help',
        ]);
        $this->addInteger($builder, 'retryIntervalSeconds', [
            'label'    => 'form.monitor.retry_interval',
            'required' => false,
            'attr'     => ['min' => 30],
        ]);
        $this->addNumber($builder, 'requestTimeoutSeconds', [
            'label'    => 'form.monitor.timeout',
            'required' => false,
            'html5'    => true,
            'scale'    => 1,
            'attr'     => ['min' => 1, 'step' => 1],
        ]);
        $this->addInteger($builder, 'resendNotificationAfterDown', [
            'label'    => 'form.monitor.resend_down',
            'required' => false,
            'attr'     => ['min' => 0],
            'help'     => 'form.monitor.resend_down_help',
        ]);
        $this->addTextarea($builder, 'description', [
            'label'    => 'form.monitor.description',
            'required' => false,
            'attr'     => ['rows' => 2],
        ]);
        $this->addUrl($builder, 'url', ['label' => 'form.monitor.url', 'required' => false]);
        $this->addChoice($builder, 'method', [
            'label'    => 'form.monitor.method',
            'choices'  => ['GET' => 'GET', 'HEAD' => 'HEAD', 'POST' => 'POST'],
            'required' => false,
        ]);
        $this->addText($builder, 'expectedStatusCodes', [
            'label'    => 'form.monitor.status_codes',
            'required' => false,
            'help'     => 'form.monitor.status_codes_help',
        ]);
        $this->addInteger($builder, 'maxRedirects', [
            'label'    => 'form.monitor.max_redirects',
            'required' => false,
            'attr'     => ['min' => 0],
        ]);
        $this->addCheckbox($builder, 'ignoreTls', [
            'label'    => 'form.monitor.ignore_tls',
            'required' => false,
        ]);
        $this->addCheckbox($builder, 'upsideDown', [
            'label'    => 'form.monitor.upside_down',
            'required' => false,
        ]);
        $this->addCheckbox($builder, 'checkCertExpiry', [
            'label'    => 'form.monitor.cert_expiry',
            'required' => false,
        ]);
        $this->addText($builder, 'keyword', [
            'label'    => 'form.monitor.keyword',
            'required' => false,
        ]);
        $this->addChoice($builder, 'bodyEncoding', [
            'label'    => 'form.monitor.body_encoding',
            'required' => false,
            'choices'  => [
                'form.monitor.body_encoding.json' => 'json',
                'form.monitor.body_encoding.xml'  => 'xml',
                'form.monitor.body_encoding.none' => 'none',
            ],
        ]);
        $this->addTextarea($builder, 'httpBody', [
            'label'    => 'form.monitor.body',
            'required' => false,
            'attr'     => ['rows' => 4, 'placeholder' => '{"key": "value"}'],
        ]);
        $this->addTextarea($builder, 'httpHeaders', [
            'label'    => 'form.monitor.headers',
            'required' => false,
            'attr'     => ['rows' => 4, 'placeholder' => "Authorization: Bearer token\nX-Custom: value"],
        ]);
        $this->addText($builder, 'proxy', [
            'label'    => 'form.monitor.proxy',
            'required' => false,
            'help'     => 'form.monitor.proxy_help',
        ]);
        $this->addChoice($builder, 'authMethod', [
            'label'    => 'form.monitor.auth',
            'required' => false,
            'choices'  => [
                'form.monitor.auth.none'  => 'none',
                'form.monitor.auth.basic' => 'basic',
            ],
        ]);
        $this->addText($builder, 'authUsername', [
            'label'    => 'form.monitor.auth_user',
            'required' => false,
        ]);
        $this->addPassword($builder, 'authPassword', [
            'label'        => 'form.monitor.auth_password',
            'required'     => false,
            'always_empty' => false,
        ]);
        $this->addText($builder, 'tags', [
            'label'    => 'form.monitor.tags',
            'required' => false,
            'help'     => 'form.monitor.tags_help',
        ]);
        $this->addText($builder, 'host', ['label' => 'form.monitor.host', 'required' => false]);
        $this->addInteger($builder, 'port', ['label' => 'form.monitor.port', 'required' => false]);
        $this->addText($builder, 'hostname', ['label' => 'form.monitor.hostname', 'required' => false]);
        $this->addChoice($builder, 'recordType', [
            'label'    => 'form.monitor.dns_type',
            'required' => false,
            'choices'  => ['A' => 'A', 'AAAA' => 'AAAA', 'CNAME' => 'CNAME', 'MX' => 'MX', 'TXT' => 'TXT'],
        ]);
        $this->addText($builder, 'expectedDnsValue', [
            'label'    => 'form.monitor.dns_expected',
            'required' => false,
        ]);
        $this->addInteger($builder, 'daysBeforeExpiry', [
            'label'    => 'form.monitor.ssl_days',
            'required' => false,
        ]);
        $this->addCheckbox($builder, 'paused', [
            'label'    => 'form.monitor.paused',
            'required' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefaults([
            'data_class'    => MonitorFormData::class,
            'group_choices' => [],
        ]);
        $resolver->setAllowedTypes('group_choices', 'array');
    }
}
