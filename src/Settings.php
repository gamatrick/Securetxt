<?php

namespace Securetxt;

use RuntimeException;

/**
 * Réglages, enregistrés en clair dans settings.json à côté du coffre
 * (ils ne contiennent aucune donnée personnelle : thème, couleurs, touches...).
 */
final class Settings
{
    public const DEFAULTS = [
        'theme'       => 'smile',
        'colors'      => [],   // modifications en cours, pas encore enregistrées comme thème
        'customThemes' => [],  // thèmes enregistrés : nom => ['base' => thème prédéfini, 'colors' => [élément => couleur]]
        'wrap'        => true,
        'lineNumbers' => true,
        'autoIndent'  => true,
        'tabSize'     => 4,
        'mouse'       => true,
        'keys'        => [],
    ];

    /** Préfixe des thèmes enregistrés dans la valeur 'theme' (ex. "perso:Mon thème"). */
    public const CUSTOM = 'perso:';

    public array $v;

    public function __construct(private string $file)
    {
        $this->v = self::DEFAULTS;
        $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        if (!is_array($data)) {
            return;
        }
        foreach (self::DEFAULTS as $k => $def) {
            if (array_key_exists($k, $data) && gettype($data[$k]) === gettype($def)) {
                $this->v[$k] = $data[$k];
            }
        }
        foreach ($this->v['customThemes'] as $name => $t) {
            if (!is_array($t) || !isset(T::THEMES[$t['base'] ?? '']) || !is_array($t['colors'] ?? null)) {
                unset($this->v['customThemes'][$name]);
            }
        }
        if (!$this->themeExists($this->v['theme'])) {
            $this->v['theme'] = 'smile';
        }
        $this->v['tabSize'] = max(1, min(8, $this->v['tabSize']));
    }

    public function themeExists(string $theme): bool
    {
        $name = self::customOf($theme);
        return $name === null ? isset(T::THEMES[$theme]) : isset($this->v['customThemes'][$name]);
    }

    /** Nom du thème enregistré contenu dans $theme, ou null si c'est un thème prédéfini. */
    public static function customOf(string $theme): ?string
    {
        return strncmp($theme, self::CUSTOM, strlen(self::CUSTOM)) === 0 ? substr($theme, strlen(self::CUSTOM)) : null;
    }

    /** Nom du thème enregistré actif, ou null. */
    public function customName(): ?string
    {
        return self::customOf($this->v['theme']);
    }

    /** @return array{0:string,1:array} [thème prédéfini de base, couleurs : thème enregistré + modifications en cours] */
    public function resolveTheme(): array
    {
        $name = $this->customName();
        if ($name === null) {
            return [$this->v['theme'], $this->v['colors']];
        }
        $t = $this->v['customThemes'][$name];
        return [$t['base'], array_merge($t['colors'], $this->v['colors'])];
    }

    public function themeLabel(): string
    {
        $name = $this->customName();
        return $name === null ? T::THEMES[$this->v['theme']] : $name;
    }

    /** Thèmes proposés : prédéfinis puis enregistrés. */
    public function themeOptions(): array
    {
        $opts = T::THEMES;
        $names = array_map('strval', array_keys($this->v['customThemes']));
        natcasesort($names);
        foreach ($names as $n) {
            $opts[self::CUSTOM . $n] = $n . '  (perso)';
        }
        return $opts;
    }

    /** Enregistre l'apparence actuelle (base + couleurs) sous le nom $name et l'active. */
    public function saveTheme(string $name): void
    {
        [$base, $colors] = $this->resolveTheme();
        $themes = $this->v['customThemes'];
        $themes[$name] = ['base' => $base, 'colors' => $colors];
        $this->v['customThemes'] = $themes;
        $this->v['theme'] = self::CUSTOM . $name;
        $this->v['colors'] = [];
        $this->save();
    }

    public function deleteTheme(string $name): void
    {
        $base = $this->v['customThemes'][$name]['base'] ?? 'smile';
        unset($this->v['customThemes'][$name]);
        if ($this->customName() === $name) {
            $this->v['theme'] = $base;
            $this->v['colors'] = [];
        }
        $this->save();
    }

    public function set(string $k, $value): void
    {
        $this->v[$k] = $value;
        $this->save();
    }

    public function reset(): void
    {
        $this->v = self::DEFAULTS;
        $this->save();
    }

    private function save(): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $json = json_encode($this->v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (@file_put_contents($this->file, $json) === false) {
            throw new RuntimeException('Impossible d\'enregistrer les réglages : ' . $this->file);
        }
    }
}
