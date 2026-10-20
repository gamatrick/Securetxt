<?php

namespace Securetxt;

/**
 * Presse-papiers système :
 *  - Windows : API user32 via FFI (rapide, aucun programme externe)
 *  - Linux   : wl-copy / xclip / xsel s'ils sont installés, + séquence OSC 52 pour le terminal
 *  - sinon   : presse-papiers interne à l'application
 */
final class Clipboard
{
    private string $internal = '';
    private ?\FFI $user = null;
    private ?\FFI $kern = null;
    private ?array $tools = null;

    public function __construct(private Terminal $term)
    {
    }

    public function copy(string $text): void
    {
        $this->internal = $text;
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $this->winSet($text);
                return;
            }
            [$copy] = $this->linuxTools();
            if ($copy !== null) {
                $this->run($copy, $text);
            }
            if (strlen($text) < 100000) {
                $this->term->write("\e]52;c;" . base64_encode($text) . "\x07");
            }
        } catch (\Throwable $e) {
            // le presse-papiers interne suffit
        }
    }

    public function paste(): string
    {
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $t = $this->winGet();
            } else {
                [, $paste] = $this->linuxTools();
                $t = $paste === null ? null : $this->run($paste, null);
            }
            if ($t !== null && $t !== '') {
                return str_replace(["\r\n", "\r"], "\n", $t);
            }
        } catch (\Throwable $e) {
        }
        return $this->internal;
    }

    // ---------------------------Linux---------------------------------

    private function linuxTools(): array
    {
        if ($this->tools !== null) {
            return $this->tools;
        }
        $has = fn (string $cmd): bool => trim((string) shell_exec('command -v ' . $cmd . ' 2>/dev/null')) !== '';
        if (getenv('WAYLAND_DISPLAY') && $has('wl-copy')) {
            return $this->tools = [['wl-copy'], ['wl-paste', '-n']];
        }
        if (getenv('DISPLAY') && $has('xclip')) {
            return $this->tools = [['xclip', '-selection', 'clipboard'], ['xclip', '-selection', 'clipboard', '-o']];
        }
        if (getenv('DISPLAY') && $has('xsel')) {
            return $this->tools = [['xsel', '-b', '-i'], ['xsel', '-b', '-o']];
        }
        return $this->tools = [null, null];
    }

    private function run(array $cmd, ?string $input): ?string
    {
        $spec = [0 => ['pipe', 'r'], 1 => $input === null ? ['pipe', 'w'] : ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
        $p = @proc_open($cmd, $spec, $pipes);
        if (!is_resource($p)) {
            return null;
        }
        if ($input !== null) {
            fwrite($pipes[0], $input);
        }
        fclose($pipes[0]);
        $out = null;
        if ($input === null) {
            $out = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
        }
        proc_close($p);
        return $out === false ? null : $out;
    }

    // ---------------------------Windows---------------------------------

    private function winInit(): void
    {
        if ($this->user !== null) {
            return;
        }
        $this->user = \FFI::cdef('
            int OpenClipboard(void* hWnd); int CloseClipboard(void); int EmptyClipboard(void);
            void* GetClipboardData(unsigned int uFormat); void* SetClipboardData(unsigned int uFormat, void* hMem);
            int IsClipboardFormatAvailable(unsigned int format);', 'user32.dll');
        $this->kern = \FFI::cdef('
            void* GlobalAlloc(unsigned int uFlags, size_t dwBytes); void* GlobalLock(void* hMem);
            int GlobalUnlock(void* hMem); size_t GlobalSize(void* hMem); void* GlobalFree(void* hMem);', 'kernel32.dll');
    }

    private function winOpen(): bool
    {
        for ($i = 0; $i < 10; $i++) {
            if ($this->user->OpenClipboard(null)) {
                return true;
            }
            usleep(20000);
        }
        return false;
    }

    private function winSet(string $text): void
    {
        $this->winInit();
        $data = mb_convert_encoding(str_replace("\n", "\r\n", $text), 'UTF-16LE', 'UTF-8') . "\0\0";
        $len = strlen($data);
        $h = $this->kern->GlobalAlloc(0x0002, $len);   // GMEM_MOVEABLE
        if ($h === null) {
            return;
        }
        $p = $this->kern->GlobalLock($h);
        \FFI::memcpy($p, $data, $len);
        $this->kern->GlobalUnlock($h);
        if (!$this->winOpen()) {
            $this->kern->GlobalFree($h);
            return;
        }
        $this->user->EmptyClipboard();
        if ($this->user->SetClipboardData(13, $h) === null) {  // CF_UNICODETEXT
            $this->kern->GlobalFree($h);
        }
        $this->user->CloseClipboard();
    }

    private function winGet(): ?string
    {
        $this->winInit();
        if (!$this->user->IsClipboardFormatAvailable(13) || !$this->winOpen()) {
            return null;
        }
        $out = null;
        $h = $this->user->GetClipboardData(13);
        if ($h !== null) {
            $p = $this->kern->GlobalLock($h);
            if ($p !== null) {
                $raw = \FFI::string($p, $this->kern->GlobalSize($h));
                $this->kern->GlobalUnlock($h);
                for ($i = 0, $n = strlen($raw) - 1; $i < $n; $i += 2) {
                    if ($raw[$i] === "\0" && $raw[$i + 1] === "\0") {
                        $raw = substr($raw, 0, $i);
                        break;
                    }
                }
                $out = mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
            }
        }
        $this->user->CloseClipboard();
        return $out;
    }
}
