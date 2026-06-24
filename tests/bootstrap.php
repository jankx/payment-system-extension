<?php
// Load Composer autoloader
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// Stub WP_Post if not available
if (!class_exists('WP_Post')) {
    class WP_Post
    {
        public $ID;
        public $post_type;
        public $post_title;
        public $post_status;
        public $post_date;
        public $post_name;
        public $post_content;

        public function __construct($data = [])
        {
            foreach ($data as $key => $value) {
                $this->$key = $value;
            }
        }
    }
}

// Stub WP_Query if not available
if (!class_exists('WP_Query')) {
    class WP_Query
    {
        public $posts = [];
        public $found_posts = 0;
        public $post_count = 0;

        public function __construct($args = [])
        {
        }

        public function have_posts()
        {
            return false;
        }

        public function the_post()
        {
        }
    }
}

// Stub WP_Error if not available
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        private $errors = [];

        public function __construct($code = '', $message = '', $data = '')
        {
            if ($code) {
                $this->errors[$code] = [$message];
            }
        }

        public function get_error_message()
        {
            return reset($this->errors)[0] ?? '';
        }
    }
}

// Stub WP_REST_Request and WP_REST_Response
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        protected $params = [];

        public function __construct($method = 'GET', $route = '')
        {
        }

        public function set_param($key, $value)
        {
            $this->params[$key] = $value;
        }

        public function get_param($key)
        {
            return $this->params[$key] ?? null;
        }

        public function get_params()
        {
            return $this->params;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        protected $data;
        protected $status;

        public function __construct($data = [], $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
        }

        public function get_data()
        {
            return $this->data;
        }

        public function get_status()
        {
            return $this->status;
        }
    }
}
