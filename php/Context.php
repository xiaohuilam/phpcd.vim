<?php
namespace PHPCD;

class Context
{
    private $path;
    private $line;
    private $col;
    private $lines;
    private $inst;

    public function __construct($path, $line, $col, $lines = null)
    {
        $this->path = $path;
        $this->line = $line;
        $this->col = $col;
        if (!$lines) {
            $lines = file_get_contents($path);
            $lines = explode("\n", $lines);
        }
        $this->lines = $lines;
        $this->inst = $this->inst($lines, $line, $col);
    }

    public function getPath()
    {
        return $this->path;
    }

    public function getLine()
    {
        return $this->line;
    }

    public function getCol()
    {
        return $this->col;
    }

    public function getLines()
    {
        return $this->lines;
    }

    public function getInst()
    {
        return $this->inst;
    }

    /**
     * get the instruction at the postion ($l, $c)
     *
     * both $l and $c are started with 0, not 1 like vim!
     */
    private function inst($lines, $l, $c)
    {
        $lines = array_map(function ($l) {
            $ll = trim($l);
            if (!$ll) {
                return $l;
            }

            if ($ll[0] == '*') {
                return '';
            }

            if ($ll[0] == '/' && $ll[1] ?? null == '/') {
                return '';
            }

            return $l;
        }, $lines);

        $ol = $l;
        $oc = $c;
        for (;;) {
            $line = $lines[$l];
            while ($c >= 0) {
                if (in_array($line[$c] ?? null, ["'", ';', '{', '}', '?', '&', ',', '$', '='])) {
                    if ($line[$c] === '$') {
                        $c--;
                    }
                    goto end;
                }
                $c--;
            }

            while (strlen($lines[--$l]) == 0) {}

                $c = strlen($lines[$l]) - 1;
        }
        end:
        $cline = array_slice($lines, $l, $ol - $l + 1);
        $cline[0] = substr($cline[0], $c + 1);
        $cline_end = $ol - $l;
        if ($l == $ol) {
            $oc -= $c;
        }

        $cline[$cline_end] = (function($line) use($oc) {
            if ($oc >= strlen($line)) {
                return $line;
            }
            $stop_chars = [
                '!', '@', '%', '^', '&', '*', '/', '-', '+', '=', "'",
                ':', '>', '<', '.', '?', ';', '(', '|', '[', ')', ',', 
            ];
            for ($i = $oc; $i < strlen($line); $i++) {
                if (in_array($line[$i], $stop_chars)) {
                    break;
                }
            }
            return substr($line, 0, $line[$i] === '(' ? $i + 1 : $i);
        })($cline[$cline_end]);
        $cline = array_map('trim', $cline);

        return join('', $cline);
    }
}
