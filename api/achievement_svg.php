<?php
/** Accept static SVG shapes only; reject scripts, links and external resources. */
function validateAchievementSvg($value): string {
    if (!is_string($value) || strlen($value)>50000) throw new InvalidArgumentException('SVG должен быть текстом до 50 КБ');
    $value=trim($value);
    if ($value==='') return '';
    if (!class_exists('DOMDocument')) throw new InvalidArgumentException('Для SVG требуется расширение PHP DOM');
    if (stripos($value,'<!')!==false || strpos($value,'<?')!==false) throw new InvalidArgumentException('Вставьте только тег <svg> без деклараций');
    $previous=libxml_use_internal_errors(true);
    try {
        $doc=new DOMDocument();
        if (!$doc->loadXML($value, LIBXML_NONET)) throw new InvalidArgumentException('Некорректный SVG-код');
        $root=$doc->documentElement;
        if (!$root || $root->tagName!=='svg') throw new InvalidArgumentException('Код должен начинаться с <svg>');
        $tags=['svg','g','path','rect','circle','ellipse','line','polyline','polygon','title','desc'];
        $attrs=['xmlns','viewBox','width','height','fill','stroke','stroke-width','stroke-linecap','stroke-linejoin','stroke-miterlimit','stroke-dasharray','stroke-dashoffset','fill-rule','clip-rule','opacity','fill-opacity','stroke-opacity','d','x','y','x1','x2','y1','y2','cx','cy','r','rx','ry','points','transform','preserveAspectRatio'];
        foreach ($doc->getElementsByTagName('*') as $node) {
            if (!in_array($node->tagName,$tags,true)) throw new InvalidArgumentException('SVG содержит неподдерживаемый тег: '.$node->tagName);
            foreach ($node->attributes as $attr) {
                if (!in_array($attr->name,$attrs,true) || preg_match('/url\s*\(|javascript:|data:/i',$attr->value)) throw new InvalidArgumentException('SVG содержит неподдерживаемый атрибут: '.$attr->name);
            }
        }
        return $doc->saveXML($root);
    } finally {
        libxml_clear_errors();libxml_use_internal_errors($previous);
    }
}
