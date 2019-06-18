<?php namespace Cviebrock\EloquentSluggable\Services;

use Cocur\Slugify\Slugify;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Class SlugService
 *
 * @package Cviebrock\EloquentSluggable\Services
 */
class SlugService
{

    /**
     * @var \Illuminate\Database\Eloquent\Model;
     */
    protected $model;

    /**
     * Slug the current model.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param bool $force
     * @return bool
     */
    public function slug(Model $model, $force = false)
    {
        $this->setModel($model);

        $attributes = [];

        foreach ($this->model->sluggable() as $attribute => $config) {
            if (is_numeric($attribute)) {
                $attribute = $config;
                $config = $this->getConfiguration();
            } else {
                $config = $this->getConfiguration($config);
            }

            $slug = $this->buildSlug($attribute, $config, $force);

            $this->model->setAttribute($attribute, $slug);

            $attributes[] = $attribute;
        }

        return $this->model->isDirty($attributes);
    }

    /**
     * Get the sluggable configuration for the current model,
     * including default values where not specified.
     *
     * @param array $overrides
     * @return array
     */
    public function getConfiguration(array $overrides = [])
    {
        static $defaultConfig = null;
        if ($defaultConfig === null) {
            $defaultConfig = app('config')->get('sluggable');
        }

        return array_merge($defaultConfig, $overrides);
    }

    /**
     * Build the slug for the given attribute of the current model.
     *
     * @param string $attribute
     * @param array $config
     * @param bool $force
     * @return null|string
     */
    public function buildSlug($attribute, array $config, $force = null)
    {
        $slug = $this->checkSlugs($this->model->getTranslations($attribute)); // check for update

        if ($force || $this->needsSlugging($attribute, $config)) {
            $source = $this->getSlugSource($config['source']);

            if ($source || is_numeric($source)) {
                $slug = $this->generateSlug($source, $config, $attribute);
                $slug = $this->validateSlug($slug, $config, $attribute);
                $slug = $this->makeSlugUnique($slug, $config, $attribute);
            }
        }

        return $slug;
    }

    /**
     * Remove extra slugs
     *
     * @param $slug
     * @return mixed
     */
    protected function checkSlugs($slug){
        $locales = config('app.locales');

        if(count($slug) > 0){
            foreach ($slug as $k=>$v){
                if(!array_key_exists($k, $locales)){
                    unset($slug[$k]);
                }
            }
        }
        return $slug;
    }

    /**
     * Determines whether the model needs slugging.
     *
     * @param string $attribute
     * @param array $config
     * @return bool
     */
    protected function needsSlugging($attribute, array $config)
    {
        if (empty($this->model->getAttributeValue($attribute)) || $config['onUpdate'] === true) {
            return true;
        }

        if ($this->model->isDirty($attribute)) {
            return false;
        }

        return (!$this->model->exists);
    }

    /**
     * Get the source string for the slug.
     *
     * @param mixed $from
     * @return string
     */
    protected function getSlugSource($from)
    {
        if (is_null($from)) {
            return $this->model->__toString();
        }

        $sourceStrings = array_map(function ($key) {
            //$value = data_get($this->model, $key);
            $value = $this->model->getTranslations($key);

            if (is_bool($value)) {
                $value = (int) $value;
            }

            return $value;
        }, (array)$from);

        return $sourceStrings[0];
    }

    /**
     * Generate a slug from the given source string.
     *
     * @param string $source
     * @param array $config
     * @param string $attribute
     * @return string
     */
    protected function generateSlug($source, array $config, $attribute)
    {
        $separator = $config['separator'];
        $method = $config['method'];
        $maxLength = $config['maxLength'];

        if ($method === null) {
            $slugEngine = $this->getSlugEngine($attribute);

            foreach ($source as $key => $el){
                $slug[$key] = $slugEngine->slugify($el, $separator);
            }

        } elseif (is_callable($method)) {
            $slug = call_user_func($method, $source, $separator);
        } else {
            throw new \UnexpectedValueException('Sluggable "method" for ' . get_class($this->model) . ':' . $attribute . ' is not callable nor null.');
        }

        foreach ($slug as $key => $s){
            if (is_string($s) && $maxLength) {
                $slug[$key] = mb_substr($s, 0, $maxLength);
            }
        }

        return $slug;
    }

    /**
     * Return a class that has a `slugify()` method, used to convert
     * strings into slugs.
     *
     * @param string $attribute
     * @return Slugify
     */
    protected function getSlugEngine($attribute)
    {
        static $slugEngines = [];

        $key = get_class($this->model) . '.' . $attribute;

        if (!array_key_exists($key, $slugEngines)) {
            $engine = new Slugify();
            if (method_exists($this->model, 'customizeSlugEngine')) {
                $engine = $this->model->customizeSlugEngine($engine, $attribute);
            }

            $slugEngines[$key] = $engine;
        }

        return $slugEngines[$key];
    }

    /**
     * Checks that the given slug is not a reserved word.
     *
     * @param string $slug
     * @param array $config
     * @param string $attribute
     * @return string
     */
    protected function validateSlug(array $slug, array $config, $attribute)
    {
        $separator = $config['separator'];
        $reserved = $config['reserved'];

        if ($reserved === null) {
            return $slug;
        }

        // check for reserved names
        if ($reserved instanceof \Closure) {
            $reserved = $reserved($this->model);
        }

        if (is_array($reserved)) {

            foreach ($slug as $key => $s){

                if (in_array($s, $reserved)) {
                    $method = $config['uniqueSuffix'];
                    if ($method === null) {
                        $suffix = $this->generateSuffix($slug, $separator, collect($reserved));
                    } elseif (is_callable($method)) {
                        $suffix = call_user_func($method, $slug, $separator, collect($reserved));
                    } else {
                        throw new \UnexpectedValueException('Sluggable "uniqueSuffix" for ' . get_class($this->model) . ':' . $attribute . ' is not null, or a closure.');
                    }

                    foreach ($suffix as $k => $suffixe){
                        if($k == $key){
                            $slug[$key] = $s . $separator .$suffixe;
                        }
                    }
                }
            }

            return $slug;
        }

        throw new \UnexpectedValueException('Sluggable "reserved" for ' . get_class($this->model) . ':' . $attribute . ' is not null, an array, or a closure that returns null/array.');

    }

    /**
     * Checks if the slug should be unique, and makes it so if needed.
     *
     * @param string $slug
     * @param array $config
     * @param string $attribute
     * @return string
     */
    protected function makeSlugUnique($slug, array $config, $attribute)
    {
        if (!$config['unique']) {
            return $slug;
        }

        $method = $config['uniqueSuffix'];
        $separator = $config['separator'];
        $response = null;

        // find all models where the slug is like the current one
        $list = $this->getExistingSlugs($slug, $attribute, $config);

        if ($list->count() === 0) {
            return $slug;
        }

        if ($method === null) {
            $suffix = $this->generateSuffix($slug, $separator, $list);
        } elseif (is_callable($method)) {
            $suffix = call_user_func($method, $slug, $separator, $list);
        } else {
            throw new \UnexpectedValueException('Sluggable "uniqueSuffix" for ' . get_class($this->model) . ':' . $attribute . ' is not null, or a closure.');
        }

        foreach ($slug as $key => $s){
            foreach ($suffix as $k => $suffixe){
                if($k == $key){
                    $response[$key] = $s . $separator .$suffixe;
                }
            }
        }

        return $response;
    }

    /**
     * Generate a unique suffix for the given slug (and list of existing, "similar" slugs.
     *
     * @param string $slug
     * @param string $separator
     * @param \Illuminate\Support\Collection $list
     * @return string
     */
    protected function generateSuffix($slug, $separator, Collection $list)
    {
        $suffixe = [];

        foreach ($slug as $ks => $s){

            $test = collect();
            $len = strlen($s . $separator);

            foreach($list as $k => $al){
                if($k == $ks){
                    foreach ($al as $l){
                        $test->push($l);
                    }
                }
            }

            $test->transform(function ($value, $key) use ($len) {
                return intval(substr($value, $len));
            });

            // find the highest value and return one greater.
            $suffixe[$ks] = $test->max() + 1;
        }

        return $suffixe;
    }

    /**
     * Get all existing slugs that are similar to the given slug.
     *
     * @param string $slug
     * @param string $attribute
     * @param array $config
     * @return \Illuminate\Support\Collection
     */
    protected function getExistingSlugs($slug, $attribute, array $config)
    {
        $includeTrashed = $config['includeTrashed'];
        $separator = $config['separator'];
        $list = [];

        //dd($slug);

        foreach ($slug as $key => $s){

            $query = $this->model->where(function($q) use ($attribute, $key, $s, $separator) {
                $q->where("$attribute->$key", $s);
                $q->orWhere("$attribute->$key", 'LIKE', '"'.$s . $separator.'%');
            });

            // use the model scope to find similar slugs
            if (method_exists($this->model, 'scopeWithUniqueSlugConstraints')) {
                $query->withUniqueSlugConstraints($this->model, $attribute, $config, $s);
            }

            // include trashed models if required
            if ($includeTrashed && $this->usesSoftDeleting()) {
                $query->withTrashed();
            }

            $results = $query->select([$attribute, $this->model->getTable() . '.' . $this->model->getKeyName()])
                ->get()->toBase();

            if(count($results) > 0) {

                foreach ($results as $res){
                    $transalations = $res->getTranslations('slug');

                    foreach ($transalations as $k => $trans){
                        $list[$k][] = $trans;
                    }
                }
            }
        }

        $list = $this->filterArray($list, $separator);

        // key the results and return
        return collect($list);
    }

    /**
     * Array Unique
     *
     * @param $array
     * @return mixed
     */
    private function filterArray($array, $separator)
    {
        $datas = collect();

        foreach ($array as $key => $arr){
            $datas[$key] = array_unique($arr);
        }

        return $datas;
    }

    /**
     * Does this model use softDeleting?
     *
     * @return bool
     */
    protected function usesSoftDeleting()
    {
        return method_exists($this->model, 'bootSoftDeletes');
    }

    /**
     * Generate a unique slug for a given string.
     *
     * @param \Illuminate\Database\Eloquent\Model|string $model
     * @param string $attribute
     * @param string $fromString
     * @param array $config
     * @return string
     */
    public static function createSlug($model, $attribute, $fromString, array $config = null)
    {
        if (is_string($model)) {
            $model = new $model;
        }
        $instance = (new static())->setModel($model);

        if ($config === null) {
            $config = array_get($model->sluggable(), $attribute);
        } elseif (!is_array($config)) {
            throw new \UnexpectedValueException('SlugService::createSlug expects an array or null as the fourth argument; ' . gettype($config) . ' given.');
        }

        $config = $instance->getConfiguration($config);

        $slug = $instance->generateSlug($fromString, $config, $attribute);
        $slug = $instance->validateSlug($slug, $config, $attribute);
        $slug = $instance->makeSlugUnique($slug, $config, $attribute);

        return $slug;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return $this
     */
    public function setModel(Model $model)
    {
        $this->model = $model;

        return $this;
    }
}
