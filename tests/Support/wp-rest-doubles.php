<?php // tests/Support/wp-rest-doubles.php
// The WP_REST_Request / WP_REST_Response / WP_Error doubles, once. Four test
// files each carried a guarded copy and whichever loaded first won; the
// bootstrap now requires this file, so every file sees the same three.
//
// WP_REST_Request takes WordPress's (method, route) or the legacy params array
// the old copies took — Cluster3PickerRequest and RelationSearchRequest still
// call parent::__construct() with one. A legacy request answers get_method()
// with '' so the guard's limiter, which charges only a matching verb, treats it
// as the old method-less copies did.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        private string $verb;
        private array $params = [];
        private array $urlParams = [];
        private array $queryParams = [];
        private array $bodyParams = [];

        public function __construct(string|array $methodOrParams = 'GET', private string $route = '')
        {
            if (is_array($methodOrParams)) {
                $this->verb   = '';
                $this->params = $methodOrParams;
            } else {
                $this->verb = strtoupper($methodOrParams);
            }
        }

        public function get_method(): string { return $this->verb; }
        public function get_route(): string { return $this->route; }
        public function set_url_params(array $params): void { $this->urlParams = $params; }
        public function get_url_params(): array { return $this->urlParams; }
        public function set_query_params(array $params): void { $this->queryParams = $params; }
        public function get_query_params(): array { return $this->queryParams; }
        public function set_body_params(array $params): void { $this->bodyParams = $params; }
        public function get_body_params(): array { return $this->bodyParams; }
        public function get_json_params(): array { return $this->params; }
        public function get_file_params(): array { return []; }
        public function set_param(string $k, mixed $v): void { $this->params[$k] = $v; }

        public function get_param(string $k): mixed
        {
            return $this->bodyParams[$k] ?? $this->queryParams[$k] ?? $this->urlParams[$k] ?? $this->params[$k] ?? null;
        }

        public function get_params(): array
        {
            return $this->bodyParams + $this->queryParams + $this->urlParams + $this->params;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public function __construct(public mixed $data = null, public int $status = 200) {}
        public function get_data(): mixed { return $this->data; }
        public function get_status(): int { return $this->status; }
        public function is_error(): bool { return $this->status >= 400; }

        public function as_error(): WP_Error
        {
            $data = is_array($this->data) ? $this->data : [];

            return new WP_Error((string) ($data['code'] ?? ''), (string) ($data['message'] ?? ''), ['status' => $this->status]);
        }
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(private string $code = '', private string $msg = '', private mixed $data = null) {}
        public function get_error_code(): string { return $this->code; }
        public function get_error_message(): string { return $this->msg; }
        public function get_error_data(): mixed { return $this->data; }
    }
}
