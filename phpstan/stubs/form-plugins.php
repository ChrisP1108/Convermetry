<?php

/**
 * The third-party form-plugin symbols Convermetry's bundled providers call.
 *
 * Every one of these belongs to a plugin Convermetry does NOT depend on: each
 * provider feature-detects with class_exists()/function_exists() and registers
 * nothing when its plugin is absent (see FormProviderInterface::isAvailable()).
 * There is no package to require that would make them resolvable, and adding
 * one would turn an optional integration into a hard dependency.
 *
 * So they are declared here, narrowly: only the members the providers actually
 * call, with the types those plugins document. That is deliberately not the
 * same thing as silencing the errors — a stub means PHPStan checks the CALLS
 * (argument counts, argument types, what the return value can be), which is
 * most of the value the analysis has to offer around code that cannot be
 * exercised without six other plugins installed.
 *
 * Properties are declared where a provider reads one off a returned row.
 * Formidable and Ninja Forms return loosely-typed objects, so those returns are
 * widened rather than invented: `object|false` is what FrmEntry::getOne()
 * really does.
 */

// ── Contact Form 7 ──────────────────────────────────────────────────────────

class WPCF7_ContactForm
{
    /**
     * @param array<string, mixed> $args
     * @return array<int, self>
     */
    public static function find($args = []) {}

    /** @return int */
    public function id() {}

    /** @return string */
    public function title() {}
}

class WPCF7_Submission
{
    /** @return self|null */
    public static function get_instance() {}

    /** @return array<string, mixed>|null */
    public function get_posted_data() {}
}

// ── Formidable Forms ────────────────────────────────────────────────────────

class FrmForm
{
    /** @return array<int, object> */
    public static function get_published_forms() {}
}

class FrmEntry
{
    /**
     * @param int  $id
     * @param bool $meta Load the entry's field values into ->metas.
     * @return object|false
     */
    public static function getOne($id, $meta = false) {}
}

class FrmField
{
    /**
     * @param int $formId
     * @return array<int, object>
     */
    public static function get_all_for_form($formId) {}

    /**
     * @param string $type
     * @return bool
     */
    public static function is_no_save_field($type) {}
}

// ── Gravity Forms ───────────────────────────────────────────────────────────

class GFAPI
{
    /**
     * @param bool $active
     * @return array<int, array<string, mixed>>
     */
    public static function get_forms($active = true) {}
}

// ── Ninja Forms ─────────────────────────────────────────────────────────────

/**
 * Ninja Forms' service container.
 *
 * Only form() is declared: the provider calls nothing else on it, and it then
 * feature-detects the factory's own methods before using them, because the
 * factory's shape differs between Ninja Forms 3.x builds.
 */
class NF_Container
{
    /** @return object */
    public function form() {}
}

/**
 * The plugin's own accessor function; returns its service container.
 *
 * @return NF_Container
 */
function Ninja_Forms() {}
