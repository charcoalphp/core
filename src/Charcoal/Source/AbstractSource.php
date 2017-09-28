<?php

namespace Charcoal\Source;

use RuntimeException;
use InvalidArgumentException;

// From PSR-3
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

// From 'charcoal-config'
use Charcoal\Config\ConfigurableInterface;
use Charcoal\Config\ConfigurableTrait;

// From 'charcoal-property'
use Charcoal\Property\PropertyInterface;

// From 'charcoal-core'
use Charcoal\Model\ModelInterface;

use Charcoal\Source\SourceConfig;
use Charcoal\Source\SourceInterface;
use Charcoal\Source\Filter;
use Charcoal\Source\FilterInterface;
use Charcoal\Source\Order;
use Charcoal\Source\OrderInterface;
use Charcoal\Source\Pagination;
use Charcoal\Source\PaginationInterface;

/**
 * Full implementation, as abstract class, of the SourceInterface.
 */
abstract class AbstractSource implements
    SourceInterface,
    ConfigurableInterface,
    LoggerAwareInterface
{
    use ConfigurableTrait;
    use LoggerAwareTrait;

    /**
     * @var ModelInterface $model
     */
    private $model = null;

    /**
     * @var array $properties
     */
    private $properties = [];

    /**
     * Array of `Filter` objects
     * @var array $filters
     */
    protected $filters = [];

    /**
     * Array of `Order` object
     * @var array $orders
     */
    protected $orders = [];

    /**
     * The `Pagination` object
     * @var Pagination|null $pagination
     */
    protected $pagination = null;

    /**
     * @param array|\ArrayAccess $dependencies The class dependencies.
     * @return void
     */
    public function __construct($dependencies)
    {
        $this->setLogger($dependencies['logger']);
    }

    /**
     * Reset everything but the model.
     *
     * @return AbstractSource Chainable
     */
    public function reset()
    {
        $this->properties = [];
        $this->filters    = [];
        $this->orders     = [];
        $this->pagination = null;

        return $this;
    }

    /**
     * Initialize the source's properties with an array of data.
     *
     * @param array $data The source data.
     * @return AbstractSource Chainable
     */
    public function setData(array $data)
    {
        foreach ($data as $prop => $val) {
            $func = [$this, $this->setter($prop)];
            if (is_callable($func)) {
                call_user_func($func, $val);
                unset($data[$prop]);
            } else {
                $this->{$prop} = $val;
            }
        }
        return $this;
    }

    /**
     * Set the source's Model.
     *
     * @param ModelInterface $model The source's model.
     * @return AbstractSource Chainable
     */
    public function setModel(ModelInterface $model)
    {
        $this->model = $model;
        return $this;
    }

    /**
     * Return the source's Model.
     *
     * @throws RuntimeException If not model was previously set.
     * @return ModelInterface
     */
    public function model()
    {
        if ($this->model === null) {
            throw new RuntimeException(
                'No model set.'
            );
        }
        return $this->model;
    }

    /**
     * @return boolean
     */
    public function hasModel()
    {
        return ($this->model !== null);
    }

    /**
     * Set the properties of the source to fetch.
     *
     * This method accepts an array of property identifiers (property ident, as string)
     * that will, if supported, be fetched from the source.
     *
     * If no properties are set, it is assumed that all the Model's properties are to be fetched.
     *
     * @param array $properties The properties.
     * @return ColelectionLoader Chainable
     */
    public function setProperties(array $properties)
    {
        $this->properties = [];
        foreach ($properties as $p) {
            $this->addProperty($p);
        }
        return $this;
    }

    /**
     * @return array
     */
    public function properties()
    {
        return $this->properties;
    }

    /**
     * @param string $property Property ident.
     * @throws InvalidArgumentException If property is not a string or empty.
     * @return SourceInterface Chainable
     */
    public function addProperty($property)
    {
        if (!is_string($property)) {
            throw new InvalidArgumentException(
                'Property must be a string.'
            );
        }

        if ($property === '') {
            throw new InvalidArgumentException(
                'Property can not be empty.'
            );
        }

        $this->properties[] = $property;
        return $this;
    }

    /**
     * @param array $filters The filters to set.
     * @return SourceInterface Chainable
     */
    public function setFilters(array $filters)
    {
        $this->filters = [];
        foreach ($filters as $f) {
            $this->addFilter($f);
        }
        return $this;
    }

    /**
     * @param array $filters The filters to add.
     * @return SourceInterface Chainable
     */
    public function addFilters(array $filters)
    {
        foreach ($filters as $f) {
            $this->addFilter($f);
        }
        return $this;
    }

    /**
     * @return array
     */
    public function filters()
    {
        return $this->filters;
    }

    /**
     * Add a collection filter to the loader.
     *
     * There are 3 different ways of adding a filter:
     * - as a `Filter` object, in which case it will be added directly.
     *   - `addFilter($obj);`
     * - as an array of options, which will be used to build the `Filter` object
     *   - `addFilter(['property' => 'foo', 'val' => 42, 'operator' => '<=']);`
     * - as 3 parameters: `property`, `val` and `options`
     *   - `addFilter('foo', 42, ['operator' => '<=']);`
     *
     * @param string|array|Filter $param   The filter property, or a Filter object / array.
     * @param mixed               $val     Optional: Only used if the first argument is a string.
     * @param array               $options Optional: Only used if the first argument is a string.
     * @throws InvalidArgumentException If property is not a string or empty.
     * @return SourceInterface (Chainable)
     */
    public function addFilter($param, $val = null, array $options = null)
    {
        if ($param instanceof FilterInterface) {
            $filter = $param;
        } elseif (is_array($param)) {
            $filter = $this->createFilter();
            $filter->setData($param);
        } elseif (is_string($param) && $val !== null) {
            $filter = $this->createFilter();
            $filter->setProperty($param);
            $filter->setVal($val);
            if (is_array($options)) {
                $filter->setData($options);
            }
        } else {
            throw new InvalidArgumentException(
                'Parameter must be an array or a property ident.'
            );
        }

        if ($this->hasModel()) {
            $model = $this->model();

            if ($filter->hasProperty()) {
                $property = $filter->property();
                if (is_string($property) && $model->hasProperty($property)) {
                    $property = $model->property($property);
                    $filter->setProperty($property);
                }

                if ($property instanceof PropertyInterface) {
                    if ($property->l10n()) {
                        $filter->setProperty($property->l10nIdent());
                    }

                    if ($property->multiple()) {
                        $filter->setOperator('FIND_IN_SET');
                    }
                }
            }
        }

        $this->filters[] = $filter;

        return $this;
    }

    /**
     * @return FilterInterface
     */
    protected function createFilter()
    {
        $filter = new Filter();
        return $filter;
    }

    /**
     * @param array $orders The orders to set.
     * @return AbstractSource Chainable
     */
    public function setOrders(array $orders)
    {
        $this->orders = [];
        foreach ($orders as $o) {
            $this->addOrder($o);
        }
        return $this;
    }

    /**
     * @param array $orders The orders to add.
     * @return AbstractSource Chainable
     */
    public function addOrders(array $orders)
    {
        foreach ($orders as $o) {
            $this->addOrder($o);
        }
        return $this;
    }

    /**
     * @return array
     */
    public function orders()
    {
        return $this->orders;
    }

    /**
     * @param string|array|Order $param        The order property, or an Order object / array.
     * @param string             $mode         Optional.
     * @param array              $orderOptions Optional.
     * @throws InvalidArgumentException If the param argument is invalid.
     * @return SourceInterface Chainable
     */
    public function addOrder($param, $mode = 'asc', array $orderOptions = null)
    {
        if ($param instanceof OrderInterface) {
            $order = $param;
        } elseif (is_array($param)) {
            $order = $this->createOrder();
            $order->setData($param);
        } elseif (is_string($param)) {
            $order = $this->createOrder();
            $order->setProperty($param);
            $order->setMode($mode);
            if (isset($orderOptions['values'])) {
                $order->setValues($orderOptions['values']);
            }
        } else {
            throw new InvalidArgumentException(
                'Parameter must be an OrderInterface object or a property ident.'
            );
        }

        if ($this->hasModel()) {
            $model = $this->model();

            if ($order->hasProperty()) {
                $property = $order->property();
                if (is_string($property) && $model->hasProperty($property)) {
                    $property = $model->property($property);
                    $order->setProperty($property);
                }

                if ($property instanceof PropertyInterface) {
                    if ($property->l10n()) {
                        $order->setProperty($property->l10nIdent());
                    }
                }
            }
        }

        $this->orders[] = $order;

        return $this;
    }

    /**
     * @return OrderInterface
     */
    protected function createOrder()
    {
        $order = new Order();
        return $order;
    }

    /**
     * @param mixed $param The pagination object or array.
     * @throws InvalidArgumentException If the argument is not an object or array.
     * @return SourceInterface Chainable
     */
    public function setPagination($param)
    {
        if ($param instanceof PaginationInterface) {
            $this->pagination = $param;
        } elseif (is_array($param)) {
            $pagination = $this->createPagination();
            $pagination->setData($param);
            $this->pagination = $pagination;
        } else {
            throw new InvalidArgumentException(
                'Can not set pagination, invalid argument.'
            );
        }
        return $this;
    }

    /**
     * Get the pagination object.
     *
     * If the pagination wasn't set previously, a new (default / blank) Pagination object will be created.
     * (Always return a `PaginationInterface` object)
     *
     * @return PaginationInterface
     */
    public function pagination()
    {
        if ($this->pagination === null) {
            $this->pagination = $this->createPagination();
        }
        return $this->pagination;
    }

    /**
     * @return PaginationInterface
     */
    protected function createPagination()
    {
        $pagination = new Pagination();
        return $pagination;
    }

    /**
     * @param integer $page The page number.
     * @throws InvalidArgumentException If the page argument is not numeric.
     * @return AbstractSource Chainable
     */
    public function setPage($page)
    {
        if (!is_numeric($page)) {
            throw new InvalidArgumentException(
                'Page must be an integer.'
            );
        }
        $this->pagination()->setPage((int)$page);
        return $this;
    }

    /**
     * @return integer
     */
    public function page()
    {
        return $this->pagination()->page();
    }

    /**
     * @param integer $num The number of items to retrieve per page.
     * @throws InvalidArgumentException If the num per page argument is not numeric.
     * @return AbstractSource Chainable
     */
    public function setNumPerPage($num)
    {
        if (!is_numeric($num)) {
            throw new InvalidArgumentException(
                'Num must be an integer.'
            );
        }
        $this->pagination()->setNumPerPage((int)$num);
        return $this;
    }

    /**
     * @return integer
     */
    public function numPerPage()
    {
        return $this->pagination()->numPerPage();
    }

    /**
     * ConfigurableTrait > createConfig()
     *
     * @param array $data Optional.
     * @return SourceConfig
     */
    public function createConfig(array $data = null)
    {
        $config = new SourceConfig();
        if (is_array($data)) {
            $config->merge($data);
        }
        return $config;
    }

    /**
     * @param mixed             $ident The ID of the item to load.
     * @param StorableInterface $item  Optional item to load into.
     * @return StorableInterface
     */
    abstract public function loadItem($ident, StorableInterface $item = null);

    /**
     * @param StorableInterface|null $item The model to load items from.
     * @return array
     */
    abstract public function loadItems(StorableInterface $item = null);

    /**
     * Save an item (create a new row) in storage.
     *
     * @param StorableInterface $item The object to save.
     * @return mixed The created item ID, or false in case of an error.
     */
    abstract public function saveItem(StorableInterface $item);

    /**
     * Update an item in storage.
     *
     * @param StorableInterface $item       The object to update.
     * @param array             $properties The list of properties to update, if not all.
     * @return boolean Success / Failure
     */
    abstract public function updateItem(StorableInterface $item, array $properties = null);

    /**
     * Delete an item from storage
     *
     * @param StorableInterface $item Optional item to delete. If none, the current model object will be used..
     * @return boolean Success / Failure
     */
    abstract public function deleteItem(StorableInterface $item = null);

    /**
     * Allow an object to define how the key getter are called.
     *
     * @param string $key  The key to get the getter from.
     * @param string $case Optional. The type of case to return. camel, pascal or snake.
     * @return string The getter method name, for a given key.
     */
    protected function getter($key, $case = 'camel')
    {
        $getter = $key;

        if ($case == 'camel') {
            return $this->camelize($getter);
        } elseif ($case == 'pascal') {
            return $this->pascalize($getter);
        } else {
            return $getter;
        }
    }

    /**
     * Allow an object to define how the key setter are called.
     *
     * @param string $key  The key to get the setter from.
     * @param string $case Optional. The type of case to return. camel, pascal or snake.
     * @return string The setter method name, for a given key.
     */
    protected function setter($key, $case = 'camel')
    {
        $setter = 'set_'.$key;

        if ($case == 'camel') {
            return $this->camelize($setter);
        } elseif ($case == 'pascal') {
            return $this->pascalize($setter);
        } else {
            return $setter;
        }
    }

    /**
     * Transform a snake_case string to camelCase.
     *
     * @param string $str The snake_case string to camelize.
     * @return string The camelCase string.
     */
    private function camelize($str)
    {
        return lcfirst($this->pascalize($str));
    }

    /**
     * Transform a snake_case string to PamelCase.
     *
     * @param string $str The snake_case string to pascalize.
     * @return string The PamelCase string.
     */
    private function pascalize($str)
    {
        return implode('', array_map('ucfirst', explode('_', $str)));
    }
}
