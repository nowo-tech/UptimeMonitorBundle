<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Form;

use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Form\Model\MonitorFormData;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractUptimeFormType<MonitorFormData>
 */
final class MonitorFormType extends AbstractUptimeFormType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, int> $groupChoices */
        $groupChoices = $options['group_choices'];

        $builder
            ->add('type', ChoiceType::class, [
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
            ])
            ->add('name', TextType::class, ['label' => 'form.monitor.name'])
            ->add('parentId', ChoiceType::class, [
                'label'       => 'form.monitor.parent',
                'required'    => false,
                'placeholder' => 'form.monitor.parent_none',
                'choices'     => $groupChoices,
                'help'        => 'form.monitor.parent_help',
            ])
            ->add('project', TextType::class, [
                'label'    => 'form.monitor.project',
                'required' => false,
                'help'     => 'form.monitor.project_help',
            ])
            ->add('intervalSeconds', IntegerType::class, [
                'label' => 'form.monitor.interval',
                'attr'  => ['min' => 30],
            ])
            ->add('retries', IntegerType::class, [
                'label'    => 'form.monitor.retries',
                'required' => false,
                'attr'     => ['min' => 0],
                'help'     => 'form.monitor.retries_help',
            ])
            ->add('retryIntervalSeconds', IntegerType::class, [
                'label'    => 'form.monitor.retry_interval',
                'required' => false,
                'attr'     => ['min' => 30],
            ])
            ->add('requestTimeoutSeconds', NumberType::class, [
                'label'    => 'form.monitor.timeout',
                'required' => false,
                'html5'    => true,
                'scale'    => 1,
                'attr'     => ['min' => 1, 'step' => 1],
            ])
            ->add('resendNotificationAfterDown', IntegerType::class, [
                'label'    => 'form.monitor.resend_down',
                'required' => false,
                'attr'     => ['min' => 0],
                'help'     => 'form.monitor.resend_down_help',
            ])
            ->add('description', TextareaType::class, [
                'label'    => 'form.monitor.description',
                'required' => false,
                'attr'     => ['rows' => 2],
            ])
            ->add('url', UrlType::class, ['label' => 'form.monitor.url', 'required' => false])
            ->add('method', ChoiceType::class, [
                'label'    => 'form.monitor.method',
                'choices'  => ['GET' => 'GET', 'HEAD' => 'HEAD', 'POST' => 'POST'],
                'required' => false,
            ])
            ->add('expectedStatusCodes', TextType::class, [
                'label'    => 'form.monitor.status_codes',
                'required' => false,
                'help'     => 'form.monitor.status_codes_help',
            ])
            ->add('maxRedirects', IntegerType::class, [
                'label'    => 'form.monitor.max_redirects',
                'required' => false,
                'attr'     => ['min' => 0],
            ])
            ->add('ignoreTls', CheckboxType::class, [
                'label'    => 'form.monitor.ignore_tls',
                'required' => false,
            ])
            ->add('upsideDown', CheckboxType::class, [
                'label'    => 'form.monitor.upside_down',
                'required' => false,
            ])
            ->add('checkCertExpiry', CheckboxType::class, [
                'label'    => 'form.monitor.cert_expiry',
                'required' => false,
            ])
            ->add('keyword', TextType::class, [
                'label'    => 'form.monitor.keyword',
                'required' => false,
            ])
            ->add('bodyEncoding', ChoiceType::class, [
                'label'    => 'form.monitor.body_encoding',
                'required' => false,
                'choices'  => [
                    'form.monitor.body_encoding.json' => 'json',
                    'form.monitor.body_encoding.xml'  => 'xml',
                    'form.monitor.body_encoding.none' => 'none',
                ],
            ])
            ->add('httpBody', TextareaType::class, [
                'label'    => 'form.monitor.body',
                'required' => false,
                'attr'     => ['rows' => 4, 'placeholder' => '{"key": "value"}'],
            ])
            ->add('httpHeaders', TextareaType::class, [
                'label'    => 'form.monitor.headers',
                'required' => false,
                'attr'     => ['rows' => 4, 'placeholder' => "Authorization: Bearer token\nX-Custom: value"],
            ])
            ->add('proxy', TextType::class, [
                'label'    => 'form.monitor.proxy',
                'required' => false,
                'help'     => 'form.monitor.proxy_help',
            ])
            ->add('authMethod', ChoiceType::class, [
                'label'    => 'form.monitor.auth',
                'required' => false,
                'choices'  => [
                    'form.monitor.auth.none'  => 'none',
                    'form.monitor.auth.basic' => 'basic',
                ],
            ])
            ->add('authUsername', TextType::class, [
                'label'    => 'form.monitor.auth_user',
                'required' => false,
            ])
            ->add('authPassword', PasswordType::class, [
                'label'        => 'form.monitor.auth_password',
                'required'     => false,
                'always_empty' => false,
            ])
            ->add('tags', TextType::class, [
                'label'    => 'form.monitor.tags',
                'required' => false,
                'help'     => 'form.monitor.tags_help',
            ])
            ->add('host', TextType::class, ['label' => 'form.monitor.host', 'required' => false])
            ->add('port', IntegerType::class, ['label' => 'form.monitor.port', 'required' => false])
            ->add('hostname', TextType::class, ['label' => 'form.monitor.hostname', 'required' => false])
            ->add('recordType', ChoiceType::class, [
                'label'    => 'form.monitor.dns_type',
                'required' => false,
                'choices'  => ['A' => 'A', 'AAAA' => 'AAAA', 'CNAME' => 'CNAME', 'MX' => 'MX', 'TXT' => 'TXT'],
            ])
            ->add('expectedDnsValue', TextType::class, [
                'label'    => 'form.monitor.dns_expected',
                'required' => false,
            ])
            ->add('daysBeforeExpiry', IntegerType::class, [
                'label'    => 'form.monitor.ssl_days',
                'required' => false,
            ])
            ->add('paused', CheckboxType::class, [
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
