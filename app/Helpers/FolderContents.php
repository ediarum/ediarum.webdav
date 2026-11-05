<?php

namespace App\Helpers;

use DOMDocument;
use DOMXPath;
use Ds\Set;

class FolderContents
{
    private Set $folders;
    private Set $files;

    public function __construct()
    {
        $this->folders = new Set();
        $this->files = new Set();
    }

    public function getFolders(): Set
    {
        return $this->folders;
    }

    public function getFiles(): Set
    {
        return $this->files;
    }

    public function parseExistDBCollectionContents($xmlString)
    {
        $xml = new DOMDocument("1.0", "UTF-8");
        $xml->loadXML($xmlString);
        $xpath = new DOMXPath($xml);
        $elements = $xpath->query('/exist:result/exist:collection/*');
        foreach ($elements as $element) {
            if ($element->tagName == "exist:collection") {
                $this->folders->add(urldecode($element->getAttribute("name")));
            } elseif ($element->tagName == "exist:resource") {
                $this->files->add(urldecode($element->getAttribute("name")));
            } else {
                throw new \Exception("Unrecognized element tag name for in XML: '$xmlString'.");
            }
        }
    }

    public function parseFileSystemFolderContents($path)
    {
        $files = scandir($path);
        foreach ($files as $file) {
            if($file == '.' || $file == '..'){
                continue;
            }
            if (is_dir($path . '/' . $file) && $file != '.' && $file != '..') {
                $this->folders->add($file);
            } else {
                $this->files->add($file);
            }
        }
    }
}
