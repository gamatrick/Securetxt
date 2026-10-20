<?php

namespace Securetxt;

/** Voir Keys pour le format des touches renvoyées par readKeys(). */
interface Terminal
{
    public function start(): void;
    public function stop(): void;
    public function setMouse(bool $on): void;
    /** @return array{0:int,1:int} [largeur, hauteur] */
    public function size(): array;
    /** Attend au plus ~250 ms. @return list<string> */
    public function readKeys(): array;
    public function write(string $s): void;
}

/** Linux / macOS : terminal en mode brut via stty. */
final class PosixTerminal implements Terminal
{
    private string $saved = '';
    private bool $active = false;
    private bool $mouse = false;
    private bool $resized = false;
    private bool $hasSignals = false;
    private float $lastCheck = 0.0;
    /** @var array{0:int,1:int} */
    private array $size = [80, 24];

    public function start(): void
    {
        $this->saved = trim((string) shell_exec('stty -g < /dev/tty 2>/dev/null'));
        shell_exec('stty raw -echo < /dev/tty 2>/dev/null');
        stream_set_read_buffer(STDIN, 0);
        stream_set_blocking(STDIN, false);
        if (function_exists('pcntl_async_signals') && defined('SIGWINCH')) {
            $this->hasSignals = true;
            pcntl_async_signals(true);
            pcntl_signal(SIGWINCH, function (): void { $this->resized = true; });
        }
        $this->active = true;
        // écran alternatif, pas de retour auto, curseur caché, collage encadré,
        // touches désambiguïsées (Maj+Entrée...) sur les terminaux compatibles, titre
        $this->write("\e[?1049h\e[?7l\e[?25l\e[?2004h\e[>1u\e]0;Securetxt\x07");
        $this->size = $this->query();
    }

    public function stop(): void
    {
        if (!$this->active) {
            return;
        }
        $this->active = false;
        $this->setMouse(false);
        $this->write("\e[<u\e[?2004l\e[0m\e[2J\e[?7h\e[?25h\e[?1049l");
        if ($this->saved !== '') {
            shell_exec('stty ' . escapeshellarg($this->saved) . ' < /dev/tty 2>/dev/null');
        }
        stream_set_blocking(STDIN, true);
    }

    public function setMouse(bool $on): void
    {
        if ($on === $this->mouse) {
            return;
        }
        $this->mouse = $on;
        // 1000 = clics, 1002 = glisser bouton enfoncé (pas les mouvements libres : économe), 1006 = format SGR
        $this->write($on ? "\e[?1000h\e[?1002h\e[?1006h" : "\e[?1006l\e[?1002l\e[?1000l");
    }

    public function size(): array { return $this->size; }

    private function query(): array
    {
        $o = trim((string) shell_exec('stty size < /dev/tty 2>/dev/null'));
        if (preg_match('/^(\d+)\s+(\d+)$/', $o, $m) && (int) $m[1] > 0) {
            return [(int) $m[2], (int) $m[1]];
        }
        return [80, 24];
    }

    public function readKeys(): array
    {
        $r = [STDIN];
        $w = $e = null;
        $n = @stream_select($r, $w, $e, 0, 250000);
        if ($n === false || $n === 0) {
            if ($this->resized) {
                $this->resized = false;
                $this->size = $this->query();
                return ['RESIZE'];
            }
            if (!$this->hasSignals && microtime(true) - $this->lastCheck > 2) {
                $this->lastCheck = microtime(true);
                $s = $this->query();
                if ($s !== $this->size) {
                    $this->size = $s;
                    return ['RESIZE'];
                }
            }
            return [];
        }
        $data = (string) fread(STDIN, 65536);
        $deadline = microtime(true) + 3.0;
        while ($data !== '' && $this->incomplete($data) && microtime(true) < $deadline) {
            $paste = strpos($data, "\e[200~") !== false;
            $r = [STDIN];
            $w = $e = null;
            if (!@stream_select($r, $w, $e, 0, $paste ? 200000 : 30000)) {
                if ($paste) {
                    continue;
                }
                break;
            }
            $more = (string) fread(STDIN, 65536);
            if ($more === '') {
                break;
            }
            $data .= $more;
        }
        return Keys::parseVt($data);
    }

    private function incomplete(string $d): bool
    {
        if (preg_match('/\e(\[[\x20-\x3F]*|O)?$/', $d)) {
            return true;
        }
        $p = strrpos($d, "\e[200~");
        if ($p !== false && strpos($d, "\e[201~", $p) === false) {
            return true;
        }
        return !mb_check_encoding($d, 'UTF-8');
    }

    public function write(string $s): void
    {
        fwrite(STDOUT, $s);
        fflush(STDOUT);
    }
}

/**
 * Windows : clavier et souris lus directement dans la console (kernel32) grâce à FFI.
 * Nécessite PHP 64 bits avec extension=ffi.
 */
final class WindowsTerminal implements Terminal
{
    private const CDEF = <<<'C'
typedef struct { short X; short Y; } COORD;
typedef struct { short Left; short Top; short Right; short Bottom; } SMALL_RECT;
typedef struct {
    COORD dwSize; COORD dwCursorPosition; unsigned short wAttributes;
    SMALL_RECT srWindow; COORD dwMaximumWindowSize;
} CONSOLE_SCREEN_BUFFER_INFO;
typedef struct {
    int bKeyDown; unsigned short wRepeatCount; unsigned short wVirtualKeyCode;
    unsigned short wVirtualScanCode; unsigned short UnicodeChar; unsigned int dwControlKeyState;
} KEY_EVENT_RECORD;
typedef struct {
    COORD dwMousePosition; unsigned int dwButtonState; unsigned int dwControlKeyState; unsigned int dwEventFlags;
} MOUSE_EVENT_RECORD;
typedef struct {
    unsigned short EventType;
    union { KEY_EVENT_RECORD KeyEvent; MOUSE_EVENT_RECORD MouseEvent; unsigned char raw[16]; } Event;
} INPUT_RECORD;
void* GetStdHandle(unsigned int nStdHandle);
int GetConsoleMode(void* hConsole, unsigned int* lpMode);
int SetConsoleMode(void* hConsole, unsigned int dwMode);
int ReadConsoleInputW(void* hConsole, INPUT_RECORD* lpBuffer, unsigned int nLength, unsigned int* lpRead);
int GetConsoleScreenBufferInfo(void* hConsole, CONSOLE_SCREEN_BUFFER_INFO* lpInfo);
unsigned int WaitForSingleObject(void* hHandle, unsigned int dwMilliseconds);
C;

    private const VK = [
        0x08 => 'BACKSPACE', 0x09 => 'TAB', 0x0D => 'ENTER', 0x1B => 'ESC',
        0x21 => 'PGUP', 0x22 => 'PGDN', 0x23 => 'END', 0x24 => 'HOME',
        0x25 => 'LEFT', 0x26 => 'UP', 0x27 => 'RIGHT', 0x28 => 'DOWN', 0x2D => 'INS', 0x2E => 'DEL',
    ];

    private \FFI $k;
    private $in;
    private $out;
    private $buf;
    private $count;
    private int $inMode = 0;
    private bool $active = false;
    private bool $mouse = false;
    private int $highSurrogate = 0;
    private int $buttons = 0;
    private int $winLeft = 0;
    private int $winTop = 0;
    private string $lastPos = '';

    public function start(): void
    {
        $this->k = \FFI::cdef(self::CDEF, 'kernel32.dll');
        $this->in = $this->k->GetStdHandle(0xFFFFFFF6);   // STD_INPUT_HANDLE  (-10)
        $this->out = $this->k->GetStdHandle(0xFFFFFFF5);  // STD_OUTPUT_HANDLE (-11)
        $this->buf = $this->k->new('INPUT_RECORD[128]');
        $this->count = $this->k->new('unsigned int');
        $mode = $this->k->new('unsigned int');
        $this->k->GetConsoleMode($this->in, \FFI::addr($mode));
        $this->inMode = $mode->cdata;
        $this->applyMode();

        sapi_windows_vt100_support(STDOUT, true);
        if (function_exists('sapi_windows_cp_set')) {
            sapi_windows_cp_set(65001);
        }
        $this->active = true;
        $this->write("\e[?1049h\e[?7l\e[?25l\e]0;Securetxt\x07");
        $this->size();
    }

    /** Pas de saisie par ligne ni d'écho ; Ctrl+C devient une touche ; souris si activée. */
    private function applyMode(): void
    {
        $m = $this->inMode & ~(0x0001 | 0x0002 | 0x0004 | 0x0010 | 0x0040 | 0x0200);
        $m |= 0x0008 | 0x0080;                                   // fenêtre + drapeaux étendus
        $m |= $this->mouse ? 0x0010 : ($this->inMode & 0x0040);  // souris, sinon sélection native
        $this->k->SetConsoleMode($this->in, $m);
    }

    public function stop(): void
    {
        if (!$this->active) {
            return;
        }
        $this->active = false;
        $this->write("\e[0m\e[2J\e[?7h\e[?25h\e[?1049l");
        $this->k->SetConsoleMode($this->in, $this->inMode);
    }

    public function setMouse(bool $on): void
    {
        $this->mouse = $on;
        if ($this->active) {
            $this->applyMode();
        }
    }

    public function size(): array
    {
        $info = $this->k->new('CONSOLE_SCREEN_BUFFER_INFO');
        if ($this->k->GetConsoleScreenBufferInfo($this->out, \FFI::addr($info))) {
            $win = $info->srWindow;
            $this->winLeft = $win->Left;
            $this->winTop = $win->Top;
            return [$win->Right - $win->Left + 1, $win->Bottom - $win->Top + 1];
        }
        return [80, 25];
    }

    public function readKeys(): array
    {
        if ($this->k->WaitForSingleObject($this->in, 250) !== 0) {
            return [];
        }
        $this->k->ReadConsoleInputW($this->in, \FFI::addr($this->buf[0]), 128, \FFI::addr($this->count));
        $keys = [];
        for ($i = 0, $n = $this->count->cdata; $i < $n; $i++) {
            $rec = $this->buf[$i];
            if ($rec->EventType === 4) {          // WINDOW_BUFFER_SIZE_EVENT
                $keys[] = 'RESIZE';
            } elseif ($rec->EventType === 2) {    // MOUSE_EVENT
                $m = $this->mouseEvent($rec->Event->MouseEvent);
                if ($m !== null) {
                    $keys[] = $m;
                }
            } elseif ($rec->EventType === 1) {    // KEY_EVENT
                $ke = $rec->Event->KeyEvent;
                if (!$ke->bKeyDown) {
                    continue;
                }
                $k = $this->map($ke->wVirtualKeyCode, $ke->UnicodeChar, $ke->dwControlKeyState);
                if ($k !== null) {
                    for ($j = 0, $r = max(1, $ke->wRepeatCount); $j < $r; $j++) {
                        $keys[] = $k;
                    }
                }
            }
        }
        return $keys;
    }

    private function mouseEvent($me): ?string
    {
        $x = $me->dwMousePosition->X - $this->winLeft;
        $y = $me->dwMousePosition->Y - $this->winTop;
        $st = $me->dwControlKeyState;
        $mods = (($st & 0x0C) ? 'C' : '') . (($st & 0x10) ? 'S' : '') . (($st & 0x03) ? 'A' : '');
        $btn = $me->dwButtonState;
        $flags = $me->dwEventFlags;
        $tail = ':' . $x . ':' . $y . ':' . $mods;
        if ($flags & 0x4) {                       // MOUSE_WHEELED
            $delta = ($btn >> 16) & 0xFFFF;
            return 'MOUSE:' . ($delta >= 0x8000 ? 'wheeldown' : 'wheelup') . $tail;
        }
        if ($flags & 0x8) {                       // molette horizontale
            return null;
        }
        $btn &= 0xFFFF;
        $prev = $this->buttons;
        $this->buttons = $btn;
        if ($flags & 0x1) {                       // MOUSE_MOVED
            if (($btn & 1) && $this->lastPos !== $x . ',' . $y) {
                $this->lastPos = $x . ',' . $y;
                return 'MOUSE:drag' . $tail;
            }
            return null;
        }
        $this->lastPos = $x . ',' . $y;
        if (($btn & 1) && !($prev & 1)) {
            return 'MOUSE:press' . $tail;
        }
        if (!($btn & 1) && ($prev & 1)) {
            return 'MOUSE:release' . $tail;
        }
        if (($btn & 2) && !($prev & 2)) {
            return 'MOUSE:rpress' . $tail;
        }
        return null;
    }

    private function map(int $vk, int $uc, int $state): ?string
    {
        $ctrl = ($state & 0x0C) !== 0;   // LEFT/RIGHT_CTRL_PRESSED
        $alt = ($state & 0x03) !== 0;    // LEFT/RIGHT_ALT_PRESSED
        $shift = ($state & 0x10) !== 0;  // SHIFT_PRESSED
        $altGr = ($state & 0x01) && ($state & 0x08);
        if ($uc >= 0xD800 && $uc <= 0xDBFF) {
            $this->highSurrogate = $uc;
            return null;
        }
        if ($uc >= 0xDC00 && $uc <= 0xDFFF) {
            return mb_chr(0x10000 + (($this->highSurrogate - 0xD800) << 10) + ($uc - 0xDC00), 'UTF-8');
        }
        if ($altGr && $uc >= 32) {
            return mb_chr($uc, 'UTF-8');
        }
        if (isset(self::VK[$vk])) {
            return Keys::compose($ctrl, $shift, $alt, self::VK[$vk]);
        }
        if ($vk >= 0x70 && $vk <= 0x7B) {
            return Keys::compose($ctrl, $shift, $alt, 'F' . ($vk - 0x6F));
        }
        if (($ctrl || $alt) && (($vk >= 0x41 && $vk <= 0x5A) || ($vk >= 0x30 && $vk <= 0x39))) {
            return Keys::compose($ctrl, $shift, $alt, chr($vk));
        }
        if ($uc >= 32 && $uc !== 127) {
            return mb_chr($uc, 'UTF-8');
        }
        return null;
    }

    public function write(string $s): void
    {
        fwrite(STDOUT, $s);
        fflush(STDOUT);
    }
}
