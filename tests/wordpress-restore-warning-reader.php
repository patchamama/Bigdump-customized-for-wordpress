<?php
// Supplement public/native behavior; evaluate only the trusted fixed helper definition.
function loadWarningReader(string $path): void {
    $source=file_get_contents($path);
    $start='function wp_restore_staging_warning_count(PDO $pdo): int {';
    $end='function wp_restore_staging_intent(';
    if (substr_count($source,$start)!==1 || substr_count($source,$end)!==1) throw new RuntimeException('Trusted helper boundary missing');
    $offset=strpos($source,$start); $length=strpos($source,$end)-$offset;
    if ($length<1 || $length>4096) throw new RuntimeException('Trusted helper boundary invalid');
    eval(substr($source,$offset,$length));
}
class ReaderStatement extends PDOStatement {
    public bool $closed=false;
    public function __construct(public mixed $value, public string $fault) {}
    public function fetchColumn(int $column=0): mixed {
        if ($this->fault==='fetch') throw new RuntimeException('synthetic fetch failure');
        return $this->value;
    }
    public function closeCursor(): bool {
        $this->closed=true;
        if ($this->fault==='close') throw new RuntimeException('synthetic close failure');
        return $this->fault!=='close-false';
    }
}
class ReaderConnection extends PDO {
    public int $sets=0;
    public ?ReaderStatement $statement=null;
    public array $attributes=[];
    public function __construct(public bool|int $emulate, public string $fault, public mixed $value) {}
    public function getAttribute(int $attribute): mixed {
        if ($attribute!==PDO::ATTR_EMULATE_PREPARES) throw new RuntimeException('unexpected attribute');
        if ($this->fault==='get') throw new RuntimeException('synthetic attribute failure');
        return $this->emulate;
    }
    public function setAttribute(int $attribute, mixed $value): bool {
        if ($attribute!==PDO::ATTR_EMULATE_PREPARES) throw new RuntimeException('unexpected attribute');
        $this->attributes[]=$value; $this->sets++;
        if (($this->fault==='begin' && $this->sets===1) || ($this->fault==='restore' && $this->sets===2)) throw new RuntimeException('synthetic set failure');
        if ($this->fault==='begin-false' && $this->sets===1) return false;
        $this->emulate=$value; return true;
    }
    public function query(string $query, ?int $fetchMode=null, mixed ...$args): PDOStatement|false {
        if ($query!=='SHOW COUNT(*) WARNINGS' || !$this->emulate) throw new RuntimeException('reader protocol violated');
        if ($this->fault==='query') throw new RuntimeException('synthetic query failure');
        if ($this->fault==='query-false') return false;
        return $this->statement=new ReaderStatement($this->value,$this->fault);
    }
}
if (realpath($_SERVER['SCRIPT_FILENAME'])!==__FILE__) return; // Native fixture imports loader only.
loadWarningReader(getenv('WARNING_READER_SOURCE') ?: dirname(__DIR__).'/cms_tools.php');
$checks=0;
foreach ([false,true,0,1] as $original) {
    foreach ([['', '0', false], ['', 0, false], ['', '1', false], ['', 1292, false],
        ['get','0',true], ['begin','0',true], ['begin-false','0',true], ['query','0',true],
        ['query-false','0',true], ['fetch','0',true], ['close','0',true], ['close-false','0',true],
        ['restore','0',true], ['',false,true], ['',null,true], ['',-1,true], ['', '00',true], ['', 'bad',true], ['', '1.5',true], ['', '999999999999999999999',true]] as [$fault,$value,$mustThrow]) {
        $pdo=new ReaderConnection($original,$fault,$value); $threw=false;
        try {
            $actual=wp_restore_staging_warning_count($pdo);
            if (!$mustThrow && $actual!==(int)$value) throw new LogicException('wrong count');
        } catch (RuntimeException $error) { $threw=true; }
        if ($threw!==$mustThrow) throw new LogicException('wrong failure disposition');
        if ($fault!=='get' && ($pdo->sets!==2 || $pdo->attributes!==[true,$original])) throw new LogicException('restoration not attempted exactly');
        if ($fault!=='restore' && $pdo->emulate!==$original) throw new LogicException('attribute not restored');
        if ($pdo->statement!==null && !$pdo->statement->closed) throw new LogicException('cursor not closed');
        $checks++;
    }
}
echo 'PASS warning reader deterministic cases: '.$checks."\n";
