<?php

/**
 * Stand-ins for the Elementor / Elementor Pro Atomic Form classes Convermetry's
 * Atomic integration talks to.
 *
 * These mirror APIs verified two different ways, and the difference matters:
 *
 *  - The ELEMENTOR CORE pieces (Atomic_Form's 'e-form' type and form-name prop
 *    default, the Chips control bound to 'actions-after-submit', Section's
 *    get_items(), and the elementor/atomic-widgets/controls filter that really
 *    is applied as apply_filters(..., $this->define_atomic_controls(), $this))
 *    were checked against elementor/elementor's published source.
 *  - The ELEMENTOR PRO pieces (Action_Base, Action_Runner, and the
 *    elementor_pro/atomic_forms/actions/register hook passing the runner
 *    CLASS-STRING rather than an instance) are not published. They follow the
 *    module's documented contract and behaviour reported for Elementor Pro
 *    4.2.3 — so a green suite here proves Convermetry holds up its end of that
 *    contract, NOT that any particular Elementor Pro build implements it
 *    identically. Real-version verification is a live-site check.
 *
 * Loaded once, from the Atomic suites. Nothing else in the unit suite defines
 * these names, so a test that needs them present and a test that needs them
 * absent cannot both run in one process — which is why the "degrades without
 * Elementor" suite asserts guard BEHAVIOUR (an unusable runner registers
 * nothing) rather than trying to un-define a class.
 */

declare(strict_types=1);

namespace ElementorPro\Modules\AtomicForm\Actions;

abstract class Action_Base
{
    abstract public function get_type(): string;

    /**
     * @param array<string, mixed> $form_data
     * @param array<string, mixed> $widget_settings
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    abstract public function execute(array $form_data, array $widget_settings, array $context): array;

    /**
     * @param array<string, mixed> $additional_data
     * @return array<string, mixed>
     */
    protected function success(string $message, array $additional_data = []): array
    {
        return array_merge(['status' => 'success', 'message' => $message], $additional_data);
    }

    /**
     * @param array<string, mixed> $additional_data
     * @return array<string, mixed>
     */
    protected function failure(string $error, array $additional_data = []): array
    {
        return array_merge(['status' => 'failed', 'error' => $error], $additional_data);
    }
}

/**
 * Elementor Pro's runner. Registration is static and keyed by action type, and
 * the register hook hands out __CLASS__ rather than an instance.
 */
class Action_Runner
{
    /** @var array<string, Action_Base> */
    private static array $actions = [];

    public static function reset(): void
    {
        self::$actions = [];
    }

    public static function register_action(Action_Base $action): void
    {
        self::$actions[$action->get_type()] = $action;
    }

    public static function has_action(string $type): bool
    {
        return isset(self::$actions[$type]);
    }

    public static function create_action(string $type): ?Action_Base
    {
        return self::$actions[$type] ?? null;
    }

    /** @return array<string, Action_Base> */
    public static function get_registered_actions(): array
    {
        return self::$actions;
    }
}

/**
 * A runner that exposes none of the expected static API, so the bridge's
 * capability check has something real to refuse.
 */
class Unusable_Runner
{
}

namespace Elementor\Modules\AtomicWidgets\Controls\Base;

abstract class Atomic_Control_Base
{
    private string $bind;

    abstract public function get_type(): string;

    /** @return array<string, mixed> */
    abstract public function get_props(): array;

    public static function bind_to(string $prop_name): static
    {
        return new static($prop_name);
    }

    protected function __construct(string $prop_name)
    {
        $this->bind = $prop_name;
    }

    public function get_bind(): string
    {
        return $this->bind;
    }

    public function set_label(string $label): static
    {
        return $this;
    }
}

namespace Elementor\Modules\AtomicWidgets\Controls\Types;

use Elementor\Modules\AtomicWidgets\Controls\Base\Atomic_Control_Base;

/**
 * Chips_Control keeps its options private with a public setter and exposes them
 * only through get_props() — the exact shape the editor filter relies on.
 */
class Chips_Control extends Atomic_Control_Base
{
    /** @var array<int, array<string, mixed>> */
    private array $options = [];

    public function get_type(): string
    {
        return 'chips';
    }

    /** @param array<int, array<string, mixed>> $options */
    public function set_options(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    /** @return array<string, mixed> */
    public function get_props(): array
    {
        return ['options' => $this->options, 'freeChips' => false];
    }
}

class Text_Control extends Atomic_Control_Base
{
    public function get_type(): string
    {
        return 'text';
    }

    /** @return array<string, mixed> */
    public function get_props(): array
    {
        return [];
    }
}

namespace Elementor\Modules\AtomicWidgets\Controls;

class Section
{
    /** @var array<int, mixed> */
    private array $items = [];

    public static function make(): self
    {
        return new self();
    }

    public function set_label(string $label): self
    {
        return $this;
    }

    /** @param array<int, mixed> $items */
    public function set_items(array $items): self
    {
        $this->items = $items;

        return $this;
    }

    /** @return array<int, mixed> */
    public function get_items(): array
    {
        return $this->items;
    }
}

namespace Elementor\Modules\AtomicWidgets\PropTypes\Primitives;

class String_Prop_Type
{
    /** @var array<string, mixed>|null */
    private ?array $default = null;

    public static function make(): self
    {
        return new self();
    }

    /** @return array<string, mixed> */
    public static function generate(mixed $value): array
    {
        return ['$$type' => 'string', 'value' => $value];
    }

    public function default(mixed $value): self
    {
        $this->default = self::generate($value);

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function get_default(): ?array
    {
        return $this->default;
    }
}

namespace Elementor\Modules\AtomicWidgets\Elements\Atomic_Form;

use Elementor\Modules\AtomicWidgets\Controls\Section;
use Elementor\Modules\AtomicWidgets\Controls\Types\Chips_Control;
use Elementor\Modules\AtomicWidgets\Controls\Types\Text_Control;
use Elementor\Modules\AtomicWidgets\PropTypes\Primitives\String_Prop_Type;

class Atomic_Form
{
    public static function get_type(): string
    {
        return 'e-form';
    }

    public static function get_element_type(): string
    {
        return self::get_type();
    }

    public function get_name(): string
    {
        return static::get_element_type();
    }

    /** @return array<string, mixed> */
    public static function get_props_schema(): array
    {
        return ['form-name' => String_Prop_Type::make()->default('Form')];
    }

    /**
     * Elementor's own control tree: the actions chips control nested inside the
     * Content section, alongside unrelated siblings.
     *
     * @return array<int, mixed>
     */
    public static function build_controls(): array
    {
        return [
            Section::make()
                ->set_label('Content')
                ->set_items([
                    Text_Control::bind_to('form-name')->set_label('Form name'),
                    Chips_Control::bind_to('actions-after-submit')
                        ->set_options([
                            ['label' => 'Email', 'value' => 'email'],
                            ['label' => 'Collect submissions', 'value' => 'collect-submissions'],
                            ['label' => 'Webhook', 'value' => 'webhook'],
                        ])
                        ->set_label('Actions after submit'),
                ]),
            Section::make()
                ->set_label('Webhook')
                ->set_items([Text_Control::bind_to('webhook_url')->set_label('Webhook URL')]),
        ];
    }
}

/**
 * An Atomic form FIELD element, which must never receive the action choice even
 * though this stub deliberately carries a same-named control.
 */
class Atomic_Form_Field
{
    public function get_name(): string
    {
        return 'e-form-input';
    }

    /** @return array<int, mixed> */
    public static function build_controls(): array
    {
        return [
            \Elementor\Modules\AtomicWidgets\Controls\Section::make()->set_items([
                \Elementor\Modules\AtomicWidgets\Controls\Types\Chips_Control::bind_to('actions-after-submit')
                    ->set_options([['label' => 'Email', 'value' => 'email']]),
            ]),
        ];
    }
}
