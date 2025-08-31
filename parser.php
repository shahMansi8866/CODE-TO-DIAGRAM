<?php
// Simple, extendable code parser (Java, PHP, Python) returning JSON for UML diagramming.
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Headers: Content-Type');
  http_response_code(204);
  exit;
}
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

// Utility: standard JSON response
function respond($success, $message, $result = null, $extra = []) {
  $payload = array_merge([
    'success' => $success,
    'message' => $message,
    'result'  => $result,
  ], $extra);
  echo json_encode($payload, JSON_PRETTY_PRINT);
  exit;
}

// Read inputs
$code = isset($_POST['code']) ? (string)$_POST['code'] : '';
$language = isset($_POST['language']) ? strtolower(trim((string)$_POST['language'])) : '';
$filename = null;

if (isset($_FILES['file']) && is_array($_FILES['file']) && isset($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
  $filename = $_FILES['file']['name'] ?? null;
  $fileContent = file_get_contents($_FILES['file']['tmp_name']);
  if ($fileContent !== false && strlen(trim($fileContent)) > 0) {
    $code = (string)$fileContent;
  }
}

// Auto-detect language if not provided
if ($language === '') {
  if ($filename) {
    $lower = strtolower($filename);
    if (str_ends_with($lower, '.java')) $language = 'java';
    elseif (str_ends_with($lower, '.php')) $language = 'php';
    elseif (str_ends_with($lower, '.py')) $language = 'python';
  }
  if ($language === '' && $code) {
    // naive heuristics
    if (preg_match('/\bclass\s+\w+\s*(?:extends|implements)/', $code) || preg_match('/;\s*$/m', $code)) {
      $language = 'java';
    }
    if (preg_match('/<\?php/', $code) || preg_match('/\bfunction\s+\w+\s*\(/', $code)) {
      $language = $language ?: 'php';
    }
    if (preg_match('/^class\s+\w+\s*($$[^)]*$$)?:\s*$/m', $code) || preg_match('/^\s*def\s+\w+\s*\(/m', $code)) {
      $language = $language ?: 'python';
    }
  }
}

// Basic validation
if (!$code || strlen(trim($code)) === 0) {
  respond(false, 'No code provided.', null, ['languageDetected' => $language ?: null]);
}

if (!$language) {
  $language = 'java';
}

// ---------- Helpers ----------
function find_matching_brace($text, $startPos) {
  $len = strlen($text);
  $depth = 0;
  for ($i = $startPos; $i < $len; $i++) {
    $ch = $text[$i];
    if ($ch === '{') $depth++;
    elseif ($ch === '}') {
      $depth--;
      if ($depth === 0) return $i;
    }
  }
  return -1;
}

function extract_class_blocks_brace_lang($code, $classRegex) {
  // Returns array of ['name' => string, 'header' => string, 'block' => string]
  $blocks = [];
  if (preg_match_all($classRegex, $code, $matches, PREG_OFFSET_CAPTURE)) {
    foreach ($matches[0] as $idx => $m) {
      $fullStart = $m[1];
      $name = $matches[1][$idx][0] ?? null;
      $headerEnd = strpos($code, '{', $fullStart);
      if ($headerEnd === false) continue;
      $end = find_matching_brace($code, $headerEnd);
      if ($end === -1) continue;
      $header = substr($code, $fullStart, $headerEnd - $fullStart);
      $block = substr($code, $headerEnd, $end - $headerEnd + 1);
      $blocks[] = ['name' => $name, 'header' => $header, 'block' => $block];
    }
  }
  return $blocks;
}

// ---------- Java Parser ----------
function parse_java($code) {
  $result = ['classes' => [], 'functions' => [], 'relationships' => []];

  $blocks = extract_class_blocks_brace_lang($code, '/\bclass\s+(\w+)[^{]*\{/');

  foreach ($blocks as $b) {
    $name = $b['name'];
    $header = $b['header'];
    $block = $b['block'];

    $extends = null;
    $implements = [];

    if (preg_match('/\bextends\s+(\w+)/', $header, $m)) $extends = $m[1];
    if (preg_match('/\bimplements\s+([^{]+)/', $header, $m)) {
      $list = array_map('trim', explode(',', $m[1]));
      $implements = array_values(array_filter($list, fn($s) => $s !== ''));
    }

    // Attributes
    $attributes = [];
    if (preg_match_all('/\b(public|private|protected)\s+(?:static\s+)?([\w<>\[\]]+)\s+(\w+)\s*(?:=\s*[^;]+)?;/m', $block, $am, PREG_SET_ORDER)) {
      foreach ($am as $m) {
        $attributes[] = [
          'name' => $m[3],
          'type' => $m[2],
          'visibility' => $m[1],
        ];
      }
    }

    // Methods
    if (preg_match_all('/\b(public|private|protected)?\s*(?:static\s+)?([\w<>\[\]]+)?\s+(\w+)\s*$$([^)]*)$$\s*\{/m', $block, $mm, PREG_SET_ORDER)) {
      $methods = [];
      foreach ($mm as $m) {
        $vis = trim($m[1] ?? '') ?: 'public';
        $ret = trim($m[2] ?? '') ?: '';
        $nameM = $m[3];
        $params = preg_replace('/\s+/', ' ', trim($m[4] ?? ''));
        $methods[] = [
          'name' => $nameM,
          'params' => $params,
          'returns' => $ret,
          'visibility' => $vis,
        ];
      }
    } else {
      $methods = [];
    }

    $result['classes'][] = [
      'name' => $name,
      'attributes' => $attributes,
      'methods' => $methods,
      'extends' => $extends,
      'implements' => $implements,
    ];

    if ($extends) $result['relationships'][] = ['from' => $name, 'to' => $extends, 'type' => 'extends'];
    foreach ($implements as $iface) {
      $result['relationships'][] = ['from' => $name, 'to' => $iface, 'type' => 'implements'];
    }
  }

  return $result;
}

// ---------- PHP Parser ----------
function parse_php_lang($code) {
  $result = ['classes' => [], 'functions' => [], 'relationships' => []];

  $blocks = extract_class_blocks_brace_lang($code, '/\bclass\s+(\w+)[^{]*\{/');

  foreach ($blocks as $b) {
    $name = $b['name'];
    $header = $b['header'];
    $block = $b['block'];

    $extends = null;
    if (preg_match('/\bextends\s+(\w+)/', $header, $m)) $extends = $m[1];

    // Properties: (public|protected|private) static? $name;
    $attributes = [];
    if (preg_match_all('/\b(public|protected|private)\s+(?:static\s+)?\$(\w+)\s*(?:=\s*[^;]+)?;/m', $block, $pm, PREG_SET_ORDER)) {
      foreach ($pm as $m) {
        $attributes[] = [
          'name' => '$' . $m[2],
          'type' => '',
          'visibility' => $m[1],
        ];
      }
    }

    // Methods: visibility? static? function name(params)
    $methods = [];
    if (preg_match_all('/\b(public|protected|private)?\s*(?:static\s+)?function\s+(\w+)\s*$$([^)]*)$$/m', $block, $mm, PREG_SET_ORDER)) {
      foreach ($mm as $m) {
        $vis = trim($m[1] ?? '') ?: 'public';
        $nameM = $m[2];
        $params = preg_replace('/\s+/', ' ', trim($m[3] ?? ''));
        $methods[] = [
          'name' => $nameM,
          'params' => $params,
          'returns' => '',
          'visibility' => $vis,
        ];
      }
    }

    $result['classes'][] = [
      'name' => $name,
      'attributes' => $attributes,
      'methods' => $methods,
      'extends' => $extends,
      'implements' => [],
    ];
    if ($extends) $result['relationships'][] = ['from' => $name, 'to' => $extends, 'type' => 'extends'];
  }

  // Top-level functions
  if (preg_match_all('/^\s*function\s+(\w+)\s*$$([^)]*)$$/m', $code, $fm, PREG_SET_ORDER)) {
    foreach ($fm as $m) {
      $result['functions'][] = [
        'name' => $m[1],
        'params' => preg_replace('/\s+/', ' ', trim($m[2] ?? '')),
        'returns' => '',
      ];
    }
  }

  return $result;
}

// ---------- Python Parser ----------
function parse_python($code) {
  $result = ['classes' => [], 'functions' => [], 'relationships' => []];

  // Classes like: class Name(Base1, Base2):
  if (preg_match_all('/^class\s+(\w+)\s*($$([^)]*)$$)?:\s*$/m', $code, $cm, PREG_OFFSET_CAPTURE)) {
    for ($i = 0; $i < count($cm[0]); $i++) {
      $className = $cm[1][$i][0];
      $parents = trim($cm[3][$i][0] ?? '');
      $startPos = $cm[0][$i][1];

      // Extract block until next class at BOL or EOF
      $rest = substr($code, $startPos + strlen($cm[0][$i][0]));
      $endRel = preg_match('/^class\s+\w+/m', $rest, $em, PREG_OFFSET_CAPTURE) ? $em[0][1] : strlen($rest);
      $block = substr($rest, 0, $endRel);

      // Methods: indented def
      $methods = [];
      if (preg_match_all('/^\s+def\s+(\w+)\s*$$([^)]*)$$\s*:/m', $block, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $m) {
          $methods[] = [
            'name' => $m[1],
            'params' => preg_replace('/\s+/', ' ', trim($m[2] ?? '')),
            'returns' => '',
            'visibility' => 'public',
          ];
        }
      }

      // Attributes: self.x = ...
      $attributes = [];
      if (preg_match_all('/^\s+self\.(\w+)\s*=\s*.+$/m', $block, $am, PREG_SET_ORDER)) {
        foreach ($am as $m) {
          $attributes[] = [
            'name' => $m[1],
            'type' => '',
            'visibility' => 'public',
          ];
        }
      }

      $extends = null;
      $implements = [];
      if ($parents !== '') {
        $plist = array_values(array_filter(array_map('trim', explode(',', $parents))));
        if (count($plist) > 0) {
          $extends = $plist[0];
          if (count($plist) > 1) {
            for ($k = 1; $k < count($plist); $k++) {
              $implements[] = $plist[$k];
            }
          }
        }
      }

      $result['classes'][] = [
        'name' => $className,
        'attributes' => $attributes,
        'methods' => $methods,
        'extends' => $extends,
        'implements' => $implements,
      ];

      if ($extends) $result['relationships'][] = ['from' => $className, 'to' => $extends, 'type' => 'extends'];
      foreach ($implements as $iface) {
        $result['relationships'][] = ['from' => $className, 'to' => $iface, 'type' => 'implements'];
      }
    }
  }

  // Top-level functions: def name(...):
  if (preg_match_all('/^def\s+(\w+)\s*$$([^)]*)$$\s*:/m', $code, $fm, PREG_SET_ORDER)) {
    foreach ($fm as $m) {
      // Exclude if indented (class method)
      if (preg_match('/^\s+def/', $m[0])) continue;
      $result['functions'][] = [
        'name' => $m[1],
        'params' => preg_replace('/\s+/', ' ', trim($m[2] ?? '')),
        'returns' => '',
      ];
    }
  }

  return $result;
}

// ---------- Parser Dispatcher ----------
function merge_results($base, $add) {
  $base['classes'] = array_merge($base['classes'] ?? [], $add['classes'] ?? []);
  $base['functions'] = array_merge($base['functions'] ?? [], $add['functions'] ?? []);
  $base['relationships'] = array_merge($base['relationships'] ?? [], $add['relationships'] ?? []);
  return $base;
}

$result = ['classes' => [], 'functions' => [], 'relationships' => []];

switch ($language) {
  case 'java':
    $result = parse_java($code);
    break;
  case 'php':
    $result = parse_php_lang($code);
    break;
  case 'python':
    $result = parse_python($code);
    break;
  default:
    $result = merge_results($result, parse_java($code));
    $result = merge_results($result, parse_php_lang($code));
    $result = merge_results($result, parse_python($code));
    break;
}

// Fallback message if nothing detected
$message = null;
if ((count($result['classes']) === 0) && (count($result['functions']) === 0)) {
  $message = 'No classes or functions detected for the selected/auto-detected language.';
}

respond(true, $message ?? 'OK', $result, [
  'languageDetected' => $language,
  'filename' => $filename,
]);
