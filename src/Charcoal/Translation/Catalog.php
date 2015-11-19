<?php

namespace Charcoal\Translation;

// Dependencies from `PHP`
use \ArrayAccess;
use \InvalidArgumentException;

// Intra-module (`charcoal-core`) dependencies
use \Charcoal\Config\ConfigurableInterface;
use \Charcoal\Config\ConfigurableTrait;

// Local namespace dependencies
use \Charcoal\Translation\CatalogInterface;

/**
* Translation Catalog
*/
class Catalog implements
    CatalogInterface,
    ConfigurableInterface,
    ArrayAccess
{
    use ConfigurableTrait;

    /**
    * The array of translations, as a $lang => $val hash.
    * @var array $translation_map
    */
    private $translation_map = [];

    /**
    * Current language
    * @var string $lang
    */
    private $lang;

    /**
    * ArrayAccess -> offsetExists()
    * Called when using the objects as `isset($obj['offset'])`
    * @param string $offset
    * @throws InvalidArgumentException
    * @return boolean
    */
    public function offsetExists($offset)
    {
        if (!is_string($offset)) {
            throw new InvalidArgumentException('Can not get numeric array keys. Use string for array acccess.');
        } else {
            return isset($this->translation_map[$offset]);
        }
    }

    /**
    * ArrayAccess -> offsetGet()
    * Get the translated string, in the current language.
    *
    * @param string $offset
    * @throws InvalidArgumentException
    * @return string
    */
    public function offsetGet($offset)
    {
        if (!is_string($offset)) {
            throw new InvalidArgumentException('Can not get numeric array keys. Use string for array acccess.');
        }
        return $this->tr($offset);
    }

    /**
    * ArrayAccess -> offsetSet()
    * Called when using $
    * @param string $offset
    * @param mixed $value
    * @throws InvalidArgumentException
    * @return void
    */
    public function offsetSet($offset, $value)
    {
        if (!is_string($offset)) {
            throw new InvalidArgumentException('Can not set numeric array keys. Use string for array acccess.');
        }
        if (is_array($value)) {
            $this->add_translation($offset, $value);
        } elseif (is_string($value)) {
             $this->add_translation_lang($offset, $value);
        } else {
            throw new InvalidArgumentException('Invalid value argument.');
        }

    }
    /**
    * ArrayAccess -> offsetUnset()
    * Called when using `unset($obj[$offset]);`
    * @throws InvalidArgumentException
    * @return void
    */
    public function offsetUnset($offset)
    {
        if (!is_string($offset)) {
            throw new InvalidArgumentException('Can not set numeric array keys. Use string for array acccess.');
        }

        if (isset($this->translation_map[$offset])) {
            unset($this->translation_map[$offset]);
        }
    }

    /**
    * Add a translation resource to the catalog.
    *
    * @param ResourceInterface|array|string $resource
    * @throws InvalidArgumentException
    * @return self
    */
    public function add_resource($resource)
    {
        if ($resource instanceof ResourceInterface) {
            throw new InvalidArgumentException('Soon');
        } elseif (is_array($resource)) {
            foreach ($resource as $ident => $translations) {
                $this->add_translation($ident, $translations);
            }
        } elseif (is_string($resource)) {
            // Try to load the current resource
            throw new InvalidArgumentException('String resource not (yet) supported');
        }

        return $this;
    }

    /**
    * Add a translation to the catalog
    *
    * @param string $ident
    * @param TranslationStringInterface|array $translations
    * @throws InvalidArgumentException
    * @return self
    */
    public function add_translation($ident, $translations)
    {
        if (!is_string($ident)) {
            throw new InvalidArgumentException('Ident must be a string');
        }
        if ($translations instanceof TranslationStringInterface) {
            $translations = $translations->all();
        }

        if (!is_array($translations)) {
            throw new InvalidArgumentException('Translations must be an array or a StringInterface object');
        }
        foreach ($translations as $lang => $str) {
            $this->add_translation_lang($ident, $str, $lang);
        }
        return $this;
    }

    /**
    * @param string $ident
    * @param string $translation
    * @param string|null $lang
    * @throws InvalidArgumentException
    * @return self
    *
    */
    public function add_translation_lang($ident, $translation, $lang = null)
    {
        if (!is_string($ident)) {
            throw new InvalidArgumentException('Ident must be a string');
        }
        if (!is_string($translation)) {
            throw new InvalidArgumentException('Tranlsation must be a string');
        }
        if ($lang === null) {
            $lang = $this->lang();
        }
        if (!in_array($lang, $this->available_langs())) {
            throw new InvalidArgumentException('Invalid lang');
        }

        if (isset($this->translation_map[$ident])) {
            $this->translation_map[$ident][$lang] = $translation;
        } else {
            $this->translation_map[$ident] = [$lang=>$translation];
        }
        return $this;
    }

    /**
    * Get the full arrays
    * Optionally filter by language.
    *
    * @param string|null $lang (Optional) If set, discard results where lang is not set.
    * @throws InvalidArgumentException
    * @return array
    */
    public function available_translations($lang = null)
    {
        if ($lang===null) {
            return array_keys($this->translation_map);
        }

        if (!in_array($lang, $this->available_langs())) {
            throw new InvalidArgumentException('Invalid lang');
        }
        $ret = [];
        foreach ($this->translation_map as $ident => $translations) {
            if (isset($translations[$lang])) {
                $ret[] = $ident;
            }
        }
        return $ret;
    }

    /**
    * @param string $ident
    * @param string|null $lang
    * @throws InvalidArgumentException
    * @return string
    */
    public function tr($ident, $lang = null)
    {
        if ($lang === null) {
            $lang = $this->lang();
        }
        if (!in_array($lang, $this->available_langs())) {
            throw new InvalidArgumentException('Invalid lang');
        }
        if (isset($this->translation_map[$ident])) {
            if (isset($this->translation_map[$ident][$lang])) {
                return $this->translation_map[$ident][$lang];
            } else {
                return $ident;
            }
        } else {
            return $ident;
        }
    }

    /**
    * @param string $lang
    * @throws InvalidArgumentException
    * @return self
    */
    public function set_lang($lang)
    {
        if (!in_array($lang, $this->available_langs())) {
            throw new InvalidArgumentException('Invalid lang');
        }
        $this->lang = $lang;
        return $this;
    }

    /**
    * Get the actual language (Set in either the object or the dfault configuration)
    * Typically from config / session.
    * @return string
    */
    public function lang()
    {
        if (!$this->lang) {
            $this->lang = $this->default_lang();
        }
        return $this->lang;
    }

    /**
    * Get the default language (used when none is set/specified).
    * Typicaly from config.
    * @return string
    */
    private function default_lang()
    {
        $translation_config = $this->config();
        return $translation_config->lang();
    }

    /**
    * @return array
    */
    private function available_langs()
    {
        $translation_config = $this->config();
        return $translation_config->available_langs();
    }

    /**
    * ConfigurableInterface > create_config()
    *
    * @see    TranslationString::create_config() for another copy of this method
    * @param  array $data Optional
    * @return TranslationConfig
    *
    * @todo   Get the latest created instance of the config.
    */
    private function create_config(array $data = null)
    {
        $config = new TranslationConfig();
        if ($data !== null) {
            $config->set_data($data);
        }
        return $config;
    }
}
