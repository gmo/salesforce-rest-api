<?php

namespace Gmo\Salesforce;

class QueryCompiler
{
    /**
     * @param string $query
     * @param array  $parameters
     *
     * @return string
     */
    public function compile($query, $parameters = [])
    {
        if (empty($parameters)) {
            return $query;
        }

        if ($this->isNumeric($parameters)) {
            $search = array_fill(0, count($parameters), '?');
        } else {
            // krsort to make the longest keys first. This prevent
            // keys from prematurely replacing parts of another one.
            // For example:
            //  Query: "Hi :foobar"
            //  Parameters: ['foo' => 1, 'foobar' => 2]
            //  Should result in "Hi 2"
            //  Without this it would be "Hi 1bar"
            krsort($parameters);
            $search = array_map(function ($string) {
                return ':' . $string;
            }, array_keys($parameters));
        }

        $replace = array_values($parameters);

        $replace = $this->addQuotesToStringReplacements($replace);
        $replace = $this->replaceBooleansWithStringLiterals($replace);

        $result = str_replace($search, $replace, $query);

        return $result;
    }

    protected function isNumeric($collection)
    {
        return array_reduce(
            array_map('is_int', array_keys($collection)),
            function ($carry, $item) {
                return $carry && $item;
            },
            true
        );
    }

    protected function addQuotesToStringReplacements($replacements)
    {
        foreach ($replacements as $key => $val) {
            if (is_string($val) && !$this->isDateString($val)) {
                $val = str_replace("'", "\\'", $val);
                $replacements[$key] = "'{$val}'";
            }
        }

        return $replacements;
    }

    protected function isDateString($string)
    {
        return preg_match('/\d+[-]\d+[-]\d+[T]\d+[:]\d+[:]\d+[Z]/', $string) === 1;
    }

    protected function replaceBooleansWithStringLiterals($replacements)
    {
        return array_map(function ($val) {
            return is_bool($val) ? ($val ? 'true' : 'false') : $val;
        }, $replacements);
    }
}
