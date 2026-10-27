<?php
// A deliberately narrow SQL dialect, never a general SQL/client command interpreter.
// The caller supplies a new schema-restricted PDO account, with multi-statements disabled.
function upgrade_import_statements($sql, $prefix) {
    if (!is_string($sql) || strlen($sql)>33554432 || str_contains($sql,"\0")) throw new RuntimeException('Unsupported SQL input.');
    $statements=[]; $statement=''; $plain=''; $quote=null; $length=strlen($sql);
    for ($i=0;$i<$length;$i++) {
        $c=$sql[$i]; $next=$sql[$i+1] ?? '';
        if ($quote!==null) {
            $statement.=$c;
            if ($c==='\\') { if ($quote==='`' || $i+1===$length) throw new RuntimeException('Unsafe SQL escape.'); $statement.=$sql[++$i]; }
            elseif ($c===$quote) { if ($next===$quote) $statement.=$sql[++$i]; else $quote=null; }
            continue;
        }
        if ($c==='\'' || $c==='"' || $c==='`') { $quote=$c; $statement.=$c; $plain.=' '; continue; }
        if ($c==='\\' || ($c==='/' && $next==='*')) throw new RuntimeException('Client commands and executable comments are forbidden.');
        if ($c==='#' || ($c==='-' && $next==='-' && ctype_space($sql[$i+2] ?? ''))) {
            while ($i<$length && $sql[$i]!=="\n") $i++;
            $statement.="\n"; $plain.=' '; continue;
        }
        if ($c===';') {
            if (trim($statement)!=='') {
                if (!preg_match('/^\s*(?:CREATE TABLE|INSERT INTO)\s+`('.preg_quote($prefix,'/').'[A-Za-z0-9_]{1,64})`\s*(?:\(|VALUES\b)/i',$statement,$m)
                    || strlen($m[1])>64 || preg_match('/[.\\\\]|\b(?:SELECT|LOAD|OUTFILE|DUMPFILE|INFILE|GRANT|REVOKE|TRIGGER|PROCEDURE|FUNCTION|EVENT|REFERENCES|FOREIGN|TABLESPACE|DIRECTORY|CONNECTION|DEFINER|DELIMITER|SOURCE|SYSTEM|TEE|PAGER)\b/i',$plain)
                    || (str_starts_with(strtoupper(ltrim($statement)),'CREATE TABLE') && !preg_match('/\bENGINE\s*=\s*InnoDB\s*$/i',trim($statement)))) throw new RuntimeException('Only plain single-schema InnoDB CREATE TABLE and INSERT VALUES are supported.');
                $statements[]=$statement;
                if (count($statements)>20000) throw new RuntimeException('SQL statement limit exceeded.');
            }
            $statement=''; $plain=''; continue;
        }
        $statement.=$c; $plain.=$c;
    }
    if ($quote!==null || trim($statement)!=='' || !$statements) throw new RuntimeException('SQL must contain complete semicolon-terminated plain statements.');
    return $statements;
}

if (PHP_SAPI==='cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '')===__FILE__) {
    try {
        $prefix=$argv[1] ?? '';
        if (!preg_match('/^[A-Za-z0-9_]{1,40}$/D',$prefix)) throw new RuntimeException('Invalid prefix.');
        upgrade_import_statements(stream_get_contents(STDIN,33554433),$prefix);
    } catch (Throwable $e) { exit(1); }
}
