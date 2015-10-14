<?php
/**
 * @package   Gantry5
 * @author    RocketTheme http://www.rockettheme.com
 * @copyright Copyright (C) 2007 - 2015 RocketTheme, LLC
 * @license   Dual License: MIT or GNU/GPLv2 and later
 *
 * http://opensource.org/licenses/MIT
 * http://www.gnu.org/licenses/gpl-2.0.html
 *
 * Gantry Framework code that extends GPL code is considered GNU/GPLv2 and later
 */

namespace Gantry\Component\Layout\Version;

/**
 * Read layout from simplified yaml file.
 */
class Format2
{
    protected $scopes = [0 => 'grid', 1 => 'block'];
    protected $sections = ['atoms', 'wrapper', 'container', 'section', 'grid', 'block', 'offcanvas'];
    protected $structures = ['div', 'section', 'aside', 'nav', 'article', 'header', 'footer'];

    protected $data;
    protected $atoms;
    protected $structure;
    protected $content;

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    public function load()
    {
        $data = &$this->data;

        // Create atoms section to the layout if it doesn't exist.
        if (!isset($data['atoms'])) {
            $data['atoms'] = [];
        }
        $data['layout']['atoms'] =& $data['atoms'];

        // Add atom-prefix to all atoms.
        foreach ($data['atoms'] as &$atom) {
            if (strpos($atom, 'atom-') !== 0) {
                $atom = "atom-{$atom}";
            }
        }

        // Parse layout.
        $result = [];
        foreach ($data['layout'] as $field => &$params) {
            if (!is_array($params)) {
                $params = [];
            }
            $child = $this->parse($field, $params);
            unset($child->size);

            $result[] = $child;
        }

        return ['preset' => $data['preset']] + $result;
    }

    public function store(array $preset, array $structure)
    {
        $this->atoms = [];
        $this->structure = [];
        $this->content = [];

        $structure = ['children' => json_decode(json_encode($structure), true)];
        $structure = $this->build($structure);

        $result = [
            'version' => 2,
            'preset' => $preset,
            'layout' => $structure,
            'atoms' => $this->atoms,
            'structure' => $this->structure,
            'content' => $this->content
        ];

        return $result;
    }

    /**
     * @param int|string $field
     * @param array $content
     * @param int $scope
     * @return array
     */
    protected function parse($field, array &$content, $scope = 0)
    {
        if (is_numeric($field))  {
            // Row or block
            $result = (object) ['id' => $this->id($this->scopes[$scope]), 'type' => $this->scopes[$scope], 'subtype' => false, 'layout' => true, 'attributes' => (object) []];
            $scope = ($scope + 1) % 2;

        } else {
            // Extract: "[section-id] [size]".
            $list = explode(' ', $field, 2);
            $section_id = array_shift($list);
            $size = ((float) array_shift($list)) ?: null;

            // Extract section id if it exists: "[section]-[id]".
            $list = explode('-', $section_id, 2);

            // Get section and its type.
            $section = reset($list);
            $type = (in_array($section, $this->sections) ? $section : 'section');

            if ($type == 'grid') {
                $scope = 1;
            }
            if ($type == 'block') {
                $scope = 0;
            }
            // Extract id.
            if ($type == 'section' && in_array($section, $this->structures)) {
                $id = array_pop($list);
            } else {
                $id = $section_id;
            }

            // Build object.
            $result = isset($this->data['structure'][$section_id]) ? (array) $this->data['structure'][$section_id] : [];
            $result += [
                'id' => $section_id,
                'layout' => true,
                'type' => $type,
                'subtype' => $type !== $section ? $section : false,
                'title' => ucfirst($id),
                'attributes' => []
            ];
            $result = (object) $result;
            $result->attributes = (object) $result->attributes;

            if ($size) {
                $result->size = $size;
            }
        }

        if (!empty($content)) {
            $result->children = [];
            foreach ($content as $child => &$params) {
                if (!$params && !is_array($params)) {
                    $params = [];
                }
                if (is_array($params)) {
                    $child = $this->parse($child, $params, $scope);
                } else {
                    $child = $this->resolve($params, $scope);
                }
                if (!empty($child->size)) {
                    $result->attributes->size = $child->size;
                }
                unset($child->size);
                $result->children[] = $child;
            }
        }

        return $result;
    }

    /**
     * @param string $field
     * @param int $scope
     * @return array
     */
    protected function resolve($field, $scope)
    {
        // Extract: "[type-subtype] [size]".
        $list = explode(' ', $field, 2);
        $type_subtype = array_shift($list);
        $size = ((float) array_shift($list)) ?: null;

        // Extract sub-type if it exists: "[type]-[subtype]-[id]".
        $list = explode('-', $type_subtype);

        // Get type, subtype and id.
        $type = reset($list);
        $test = end($list);
        $id = ((string)(int) $test === (string) $test) ? array_pop($list) : null;
        if (in_array($type, ['system', 'position', 'particle', 'atom'])) {
            array_shift($list);
        } else {
            $type = 'particle';
        }
        $subtype = implode('-', $list);

        $title = ucfirst($subtype ?: $type);

        if ($type === 'system' && $subtype === 'messages') {
            $subtype = 'system-messages';
            $type = 'pagecontent';
            $title = 'System Messages';
        }
        if ($type === 'system' && $subtype === 'content') {
            $subtype = 'pagecontent';
            $type = 'pagecontent';
            $title = 'Page Content';
        }

        if ($type === 'position') {
            $key = $type . ($subtype ? "-{$subtype}" : '') . ($id !== null ? "-{$id}" : '');
            $subtype = false;
            $id = $key;
            $title = ucfirst($key);
        }

        $result = isset($this->data['content'][$type_subtype]) ? (array) $this->data['content'][$type_subtype] : [];
        $result += ['id' => $this->id($type, $subtype, $id), 'title' => $title, 'type' => $type, 'subtype' => $subtype, 'attributes' => []];

        $result['attributes'] = (object) ($result['attributes'] + ['enabled' => 1]);
        $result = (object) $result;

        if (isset($key)) {
            $result->attributes->key = $key;
        }
        if ($scope > 1) {
            if ($size) {
                $result->attributes->size = $size;
            }
        }
        if ($scope <= 1) {
            $result = (object) ['id' => $this->id('block'), 'type' => 'block', 'subtype' => false, 'layout' => true, 'children' => [$result], 'attributes' => new \stdClass];
            if ($size) {
                $result->attributes->size = $size;
            }
        }
        if ($scope == 0) {
            $result = (object) ['id' => $this->id('grid'), 'type' => 'grid', 'subtype' => false, 'layout' => true, 'children' => [$result], 'attributes' => new \stdClass];
        }

        return $result;
    }

    protected function build(&$content)
    {
        $result = [];
        $ctype = isset($content['type']) ? $content['type'] : null;

        foreach ($content['children'] as $child) {
            $value = null;
            $size = null;
            $id = $child['id'];
            $type = $child['type'];
            if ($type === 'atom') {
                // Handle atoms.
                $this->atoms[] = $id;
                $array = 'content';
            } elseif (!in_array($type, $this->sections)) {
                // Special handling for pagecontent.
               if ($type === 'pagecontent') {
                   $child['type'] = $type = 'system';
                   $child['subtype'] = $child['subtype'] === 'pagecontent' ? 'content' : 'messages';
                }
                // Special handling for positions.
                if ($type === 'position') {
                    $id = $child['attributes']['key'];
                    unset ($child['attributes']['title'], $child['attributes']['key']);
                }
                $value = $id;
                $id = null;
                $array = 'content';
                if (!empty($child['attributes']['enabled'])) {
                    unset ($child['attributes']['enabled']);
                }
            } else {
                $value = $this->build($child);
                $array = 'structure';
            }
            // Clean up defaults.
            if (!$child['title'] || $child['title'] === 'Untitled' || $child['title'] === ucfirst($child['id'])) {
                unset ($child['title']);
            }
            if (!$child['subtype']) {
                unset ($child['subtype']);
            }
            unset ($child['id'], $child['children']);
            // Embed size into array key/value.
            if ($id && $ctype === 'block' && isset($content['attributes']['size']) && $content['attributes']['size'] != 100) {
                $size = $content['attributes']['size'];
            }
            if (isset($child['attributes']['size'])) {
                if ($child['attributes']['size'] != 100 && is_string($value)) {
                    $value .= ' ' . $child['attributes']['size'];
                }
                unset ($child['attributes']['size']);
            }
            if (!$child['attributes']) {
                unset ($child['attributes']);
            }
            if (in_array($child['type'], ['grid', 'block']) && count($child) === 1) {
                $id = null;
            }
            if ($value) {
                if ($id) {
                    $result[trim("{$id} {$size}")] = $value;
                } else {
                    $result[] = $value;
                }
            }
            if ($id && count($child) > 1) {
                $this->{$array}[$id] = $child;
            }
        }

        if ($ctype && in_array($ctype, ['grid', 'block']) && count($result) <= 1 && key($result) === 0) {
            unset ($this->structure[$content['id']]);
            return reset($result) ?: null;
        }
        return $result;
    }

    protected function id($type, $subtype = null, $id = null)
    {
        static $keys = [];

        // Special handling for pagecontent.
       if ($type === 'pagecontent') {
           $type = 'system';
           $subtype = $subtype === 'pagecontent' ? 'content' : 'messages';
        }

        $result = [];
        if ($type !== 'particle' && $type !== 'atom') {
            $result[] = $type;
        }
        if ($subtype) {
            $result[] = $subtype;
        }
        $key = implode('-', $result);
        if ($id !== null) {
            return $key . '-'. $id;
        }

        if (!isset($keys[$key])) {
            $keys[$key] = 0;
        }

        return $key . '-'. ++$keys[$key];
    }
}