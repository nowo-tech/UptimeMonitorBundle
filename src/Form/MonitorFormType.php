<?php

declare(strict_types=1);

namespace Nowo\UptimeMonitorBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\UptimeMonitorBundle\Enum\MonitorType;
use Nowo\UptimeMonitorBundle\Form\Model\MonitorFormData;
use Nowo\UptimeMonitorBundle\Push\PushThresholds;
use Nowo\UptimeMonitorBundle\Translation\UptimeTranslation;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use function is_int;
use function is_string;
use function sprintf;
use function strtolower;
use function trim;
use function ucfirst;

/**
 * @extends AbstractUptimeFormType<MonitorFormData>
 */
#[FormKitConfig('uptime_monitor')]
final class MonitorFormType extends AbstractUptimeFormType
{
    /**
     * Seconds fields (1.18): the unit is a suffix next to the input (`_monitor_form_fields.html.twig`), not label text.
     * The value is a translation key of the bundle domain, translated by the template.
     */
    private const SECONDS = ['data-uptime-unit' => 'form.monitor.unit.seconds'];

    /** On/off settings (1.18): a checkbox drawn as a switch (`role="switch"`, same submitted value). */
    private const SWITCH = ['role' => 'switch', 'class' => 'uptime-switch'];

    /** Fields required for their type (1.18): the label carries the required marker. */
    private const REQUIRED_LABEL = ['class' => 'uptime-label-required'];

    /**
     * @param list<string> $hiddenFields fields left out of the form (`form.hidden_fields`); the monitor keeps their values
     * @param float $maxTimeoutSeconds `checks.max_timeout_seconds`: upper bound of the timeout field
     */
    public function __construct(
        #[Autowire('%nowo_uptime_monitor.form.hidden_fields%')]
        private readonly array $hiddenFields = [],
        #[Autowire('%nowo_uptime_monitor.checks.max_timeout_seconds%')]
        private readonly float $maxTimeoutSeconds = 60.0,
    ) {
    }

    /**
     * Adds the monitor fields.
     *
     * @param array<string, mixed> $options Form options
     */
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
                'form.monitor.type.push'  => MonitorType::Push,
            ],
            // The option values are the type names (`https`, `tcp`, …): the form's field toggle reads them.
            'choice_value' => static fn (?MonitorType $type): ?string => $type?->value,
        ]);
        // Every list, status page and alert names the monitor: a name is required.
        $this->addText($builder, 'name', [
            'label'       => 'form.monitor.name',
            'label_attr'  => self::REQUIRED_LABEL,
            'help'        => 'form.monitor.name_help',
            'attr'        => ['placeholder' => 'form.monitor.name_placeholder'],
            'constraints' => [new Callback(self::validateName(...))],
        ]);
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
        /** @var int $minInterval */
        $minInterval = $options['min_interval_seconds'];
        $this->addInteger($builder, 'intervalSeconds', [
            'label'                       => 'form.monitor.interval',
            'help'                        => 'form.monitor.interval_help',
            'help_translation_parameters' => ['%min%' => $minInterval],
            'row_attr'                    => self::SECONDS,
            'attr'                        => ['min' => $minInterval],
            'constraints'                 => [new Callback(static fn (mixed $value, ExecutionContextInterface $context) => self::validateInterval($value, $context, $minInterval))],
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
            'help'     => 'form.monitor.retry_interval_help',
            'row_attr' => self::SECONDS,
            'attr'     => ['min' => 30],
        ]);
        // Whole seconds (1.17): the decimal field rendered "48.0" ("48,0" in comma-decimal locales).
        $this->addInteger($builder, 'requestTimeoutSeconds', [
            'label'                       => 'form.monitor.timeout',
            'required'                    => false,
            'help'                        => 'form.monitor.timeout_help',
            'help_translation_parameters' => ['%max%' => (int) $this->maxTimeoutSeconds],
            'row_attr'                    => self::SECONDS,
            'attr'                        => ['min' => 1, 'max' => $this->maxTimeoutSeconds, 'step' => 1],
            'constraints'                 => [new Range(min: 1, max: $this->maxTimeoutSeconds)],
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
        // Required for HTTP(S) only: the dialog script sets `required` with the type, the server checks it
        // (MonitorFormData::validateTypeFields()).
        $this->addUrl($builder, 'url', [
            'label'      => 'form.monitor.url',
            'required'   => false,
            'label_attr' => self::REQUIRED_LABEL,
            'help'       => 'form.monitor.url_help',
            'attr'       => ['placeholder' => 'form.monitor.url_placeholder', 'data-required-for' => 'http https'],
        ]);
        $this->addChoice($builder, 'method', [
            'label'    => 'form.monitor.method',
            'choices'  => ['GET' => 'GET', 'HEAD' => 'HEAD', 'POST' => 'POST'],
            'required' => false,
            'help'     => 'form.monitor.method_help',
        ]);
        $this->addText($builder, 'expectedStatusCodes', [
            'label'    => 'form.monitor.status_codes',
            'required' => false,
            'help'     => 'form.monitor.status_codes_help',
            'attr'     => ['placeholder' => 'form.monitor.status_codes_placeholder'],
        ]);
        $this->addInteger($builder, 'maxRedirects', [
            'label'    => 'form.monitor.max_redirects',
            'required' => false,
            'help'     => 'form.monitor.max_redirects_help',
            'attr'     => ['min' => 0, 'max' => 20],
        ]);
        $this->addCheckbox($builder, 'ignoreTls', [
            'label'    => 'form.monitor.ignore_tls',
            'required' => false,
            'attr'     => self::SWITCH,
        ]);
        $this->addCheckbox($builder, 'upsideDown', [
            'label'    => 'form.monitor.upside_down',
            'required' => false,
            'attr'     => self::SWITCH,
        ]);
        $this->addCheckbox($builder, 'checkCertExpiry', [
            'label'    => 'form.monitor.cert_expiry',
            'required' => false,
            'attr'     => self::SWITCH,
        ]);
        $this->addText($builder, 'keyword', [
            'label'    => 'form.monitor.keyword',
            'required' => false,
            'help'     => 'form.monitor.keyword_help',
            'attr'     => ['placeholder' => 'form.monitor.keyword_placeholder'],
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
            'attr'     => ['placeholder' => 'form.monitor.proxy_placeholder'],
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
            'attr'     => ['placeholder' => 'form.monitor.tags_placeholder'],
        ]);
        $this->addText($builder, 'host', [
            'label'      => 'form.monitor.host',
            'required'   => false,
            'label_attr' => self::REQUIRED_LABEL,
            'attr'       => ['placeholder' => 'form.monitor.host_placeholder', 'data-required-for' => 'tcp ping ssl'],
        ]);
        $this->addInteger($builder, 'port', [
            'label'      => 'form.monitor.port',
            'required'   => false,
            'label_attr' => self::REQUIRED_LABEL,
            'attr'       => ['min' => 1, 'max' => 65535, 'data-required-for' => 'tcp ssl'],
        ]);
        $this->addText($builder, 'hostname', [
            'label'      => 'form.monitor.hostname',
            'required'   => false,
            'label_attr' => self::REQUIRED_LABEL,
            'attr'       => ['placeholder' => 'form.monitor.hostname_placeholder', 'data-required-for' => 'dns'],
        ]);
        $this->addChoice($builder, 'recordType', [
            'label'    => 'form.monitor.dns_type',
            'required' => false,
            'choices'  => ['A' => 'A', 'AAAA' => 'AAAA', 'CNAME' => 'CNAME', 'MX' => 'MX', 'TXT' => 'TXT'],
        ]);
        $this->addText($builder, 'expectedDnsValue', [
            'label'    => 'form.monitor.dns_expected',
            'required' => false,
            'attr'     => ['placeholder' => 'form.monitor.dns_expected_placeholder'],
        ]);
        $this->addInteger($builder, 'daysBeforeExpiry', [
            'label'    => 'form.monitor.ssl_days',
            'required' => false,
        ]);
        $this->addTextarea($builder, 'expectedSpkiPins', [
            'label'    => 'form.monitor.ssl_spki_pins',
            'required' => false,
            'help'     => 'form.monitor.ssl_spki_pins_help',
            'attr'     => [
                'rows'         => 3,
                'maxlength'    => 4096,
                'spellcheck'   => 'false',
                'autocomplete' => 'off',
                'placeholder'  => 'sha256/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            ],
        ]);
        $this->addInteger($builder, 'pushGraceSeconds', [
            'label'    => 'form.monitor.push_grace',
            'required' => false,
            'row_attr' => self::SECONDS,
            'attr'     => ['min' => 0],
            'help'     => 'form.monitor.push_grace_help',
        ]);
        $this->addCheckbox($builder, 'pushSigned', [
            'label'    => 'form.monitor.push_signed',
            'required' => false,
            'attr'     => self::SWITCH,
            'help'     => 'form.monitor.push_signed_help',
        ]);
        foreach (PushThresholds::DEFAULTS as $metric => $_) {
            foreach (['Warn', 'Critical'] as $level) {
                $this->addNumber($builder, 'push' . ucfirst($metric) . $level, [
                    'label'         => 'form.monitor.push_threshold.' . $metric . '_' . strtolower($level),
                    'required'      => false,
                    'html5'         => true,
                    'scale'         => 2,
                    'property_path' => sprintf('pushThresholds[%s%s]', $metric, $level),
                    'attr'          => ['min' => 0, 'max' => 100, 'step' => $metric === 'load' ? 0.1 : 1],
                    'constraints'   => [new Range(min: 0, max: 100)],
                ]);
            }
        }
        $this->addCheckbox($builder, 'paused', [
            'label'    => 'form.monitor.paused',
            'required' => false,
            'help'     => 'form.monitor.paused_help',
            'attr'     => self::SWITCH,
        ]);
        foreach ($this->hiddenFields as $field) {
            $builder->remove($field);
        }
    }

    /**
     * Adds a `form.monitor.name_required` violation (domain {@see UptimeTranslation::DOMAIN}) when the name is blank.
     */
    public static function validateName(mixed $value, ExecutionContextInterface $context): void
    {
        if (is_string($value) && trim($value) !== '') {
            return;
        }

        $context->buildViolation('form.monitor.name_required')
            ->setTranslationDomain(UptimeTranslation::DOMAIN)
            ->setCode(NotBlank::IS_BLANK_ERROR)
            ->addViolation();
    }

    /**
     * Adds a `form.monitor.interval_min` violation when a monitor that runs checks (not a group) is checked more often
     * than `$minSeconds` (`min_interval_seconds` option: the tenant limit).
     */
    public static function validateInterval(mixed $value, ExecutionContextInterface $context, int $minSeconds): void
    {
        $data = $context->getRoot()->getData();
        if (!is_int($value) || $value >= $minSeconds || ($data instanceof MonitorFormData && $data->type === MonitorType::Group)) {
            return;
        }

        $context->buildViolation('form.monitor.interval_min')
            ->setParameter('%seconds%', (string) $minSeconds)
            ->setTranslationDomain(UptimeTranslation::DOMAIN)
            ->addViolation();
    }

    /**
     * Configures the monitor options.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefaults([
            'data_class'           => MonitorFormData::class,
            'group_choices'        => [],
            'min_interval_seconds' => 30,
        ]);
        $resolver->setAllowedTypes('group_choices', 'array');
        $resolver->setAllowedTypes('min_interval_seconds', 'int');
    }
}
