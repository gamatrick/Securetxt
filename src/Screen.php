<?php

namespace Securetxt;

/** Une ligne d'écran : texte coloré, coupé ou complété à la largeur exacte. */
final class Row
{
    private string $s;
    private int $n = 0;

    public function __construct(private int $w, private string $base)
    {
        $this->s = $base;
    }

    public function add(string $t, ?string $style = null): self
    {
        $room = $this->w - $this->n;
        if ($room <= 0 || $t === '') {
            return $this;
        }
        $len = mb_strlen($t);
        if ($len > $room) {
            $t = mb_substr($t, 0, $room);
            $len = $room;
        }
        $this->s .= $style === null ? $t : $style . $t . $this->base;
        $this->n += $len;
        return $this;
    }

    public function used(): int { return $this->n; }

    public function done(): string
    {
        return $this->s . str_repeat(' ', max(0, $this->w - $this->n));
    }
}

/**
 * Affichage par différences : seules les lignes qui ont changé depuis
 * la dernière image sont réécrites (essentiel sur une petite machine).
 */
final class Screen
{
    /** @var array<int,string> */
    private array $prev = [];
    private int $h = 0;

    public function __construct(private Terminal $term)
    {
    }

    public function reset(int $w, int $h): void
    {
        $this->h = $h;
        $this->prev = [];
        $this->term->write("\e[0m\e[2J");
    }

    /** @param list<string> $rows  @param array{0:int,1:int}|null $cursor */
    public function present(array $rows, ?array $cursor): void
    {
        $out = "\e[?25l";
        for ($y = 0; $y < $this->h; $y++) {
            $r = $rows[$y] ?? '';
            if (($this->prev[$y] ?? null) !== $r) {
                $out .= "\e[" . ($y + 1) . ';1H' . $r . "\e[0m";
                $this->prev[$y] = $r;
            }
        }
        if ($cursor !== null) {
            $out .= "\e[" . ($cursor[1] + 1) . ';' . ($cursor[0] + 1) . "H\e[?25h";
        }
        $this->term->write($out);
    }
}
