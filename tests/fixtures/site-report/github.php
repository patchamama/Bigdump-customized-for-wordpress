<?php
namespace SiteReportFixture;
// Synthetic HTTPS wrapper: records only sanitized API operations in the isolated fixture.
class GitHubStream {
    public $context;
    private $body = '';
    private $offset = 0;
    public function stream_open($url, $mode, $options, &$opened_path) {
        $config = stream_context_get_options($this->context)['http'];
        $method = $config['method'];
        $path = parse_url($url, PHP_URL_PATH);
        $scenario = $_POST['fixture_scenario'] ?? '';
        $payload = json_decode($config['content'] ?? '', true);
        $operations = is_file(__DIR__.'/github-ops.json') ? json_decode(file_get_contents(__DIR__.'/github-ops.json'), true) : [];
        $operations[] = ['method'=>$method, 'path'=>$path, 'query'=>parse_url($url, PHP_URL_QUERY), 'payload'=>$payload];
        file_put_contents(__DIR__.'/github-ops.json', json_encode($operations));
        if (parse_url($url, PHP_URL_HOST) !== 'api.github.com' || $config['follow_location'] !== 0 || stream_context_get_options($this->context)['ssl']['verify_peer'] !== true) throw new \RuntimeException('Unsafe transport');
        if (!str_contains($config['header'], 'Authorization: Bearer SYNTHETIC_TOKEN_123')) throw new \RuntimeException('Missing authorization');
        $code = 200;
        $sha = str_repeat('a', 40);
        $data = ['private'=>true,'default_branch'=>'main'];
        if ($scenario === 'auth') { $code=401; $data=['message'=>'SYNTHETIC_TOKEN_123']; }
        elseif ($scenario === 'public') $data['private'] = false;
        elseif ($scenario === 'redirect') { $code=302; $data=[]; }
        elseif (str_contains($path, '/git/ref/')) {
            if ($scenario === 'empty') { $code=409; $data=[]; }
            elseif ($scenario !== 'existing' && !str_ends_with($path, '/main')) { $code=404; $data=[]; }
            else $data=['object'=>['sha'=>$sha]];
        } elseif (str_ends_with($path, '/git/refs')) { $code=201; $data=['ref'=>$payload['ref']]; }
        elseif (str_contains($path, '/contents/')) {
            if ($method === 'GET') {
                $code=$scenario === 'existing' ? 200 : 404;
                $data=['type'=>'file','sha'=>$sha];
            } else {
                $code=$scenario === 'conflict' ? 409 : ($scenario === 'existing' ? 200 : 201);
                $data=['commit'=>['sha'=>$sha]];
            }
        }
        $this->body=json_encode($data);
        // file_get_contents exposes this wrapper metadata as the HTTP response headers.
        $this->wrapper_data = ["HTTP/1.1 $code Synthetic"];
        return true;
    }
    public $wrapper_data;
    public function stream_read($count) { $chunk=substr($this->body,$this->offset,$count); $this->offset+=strlen($chunk); return $chunk; }
    public function stream_eof() { return $this->offset >= strlen($this->body); }
    public function stream_stat() { return []; }
}
// Also exercise the cURL production branch without making network requests.
function curl_init($url) { return (object)['url'=>$url, 'options'=>[], 'status'=>0]; }
function curl_setopt_array($handle, $options) { $handle->options = $options; return true; }
function curl_setopt($handle, $option, $value) { $handle->options[$option] = $value; return true; }
function curl_exec($handle) {
    $o = $handle->options;
    if ($o[CURLOPT_FOLLOWLOCATION] !== false || $o[CURLOPT_SSL_VERIFYPEER] !== true || $o[CURLOPT_SSL_VERIFYHOST] !== 2 || $o[CURLOPT_PROTOCOLS] !== CURLPROTO_HTTPS) throw new \RuntimeException('Unsafe cURL transport');
    $context = stream_context_create(['http'=>['method'=>$o[CURLOPT_CUSTOMREQUEST], 'header'=>implode("\r\n",$o[CURLOPT_HTTPHEADER]),
        'content'=>$o[CURLOPT_POSTFIELDS] ?? '', 'follow_location'=>0], 'ssl'=>['verify_peer'=>true]]);
    $stream = fopen($handle->url, 'rb', false, $context);
    $meta = stream_get_meta_data($stream);
    $handle->status = (int)explode(' ', $meta['wrapper_data'][0])[1];
    $body = stream_get_contents($stream); fclose($stream);
    return $o[CURLOPT_WRITEFUNCTION]($handle, $body) === strlen($body);
}
function curl_getinfo($handle, $option) { return $handle->status; }
function stream_get_meta_data($stream) {
    $meta = \stream_get_meta_data($stream);
    if (($meta['wrapper_data'] ?? null) instanceof GitHubStream) $meta['wrapper_data'] = $meta['wrapper_data']->wrapper_data;
    return $meta;
}
if (in_array('https', stream_get_wrappers(), true)) stream_wrapper_unregister('https');
stream_wrapper_register('https', GitHubStream::class);
