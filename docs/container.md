## Features

- Auto-wiring
- Predefined definitions
- Attribute resolution on Property
- Parameter binding

## Lets check with Example

```php
// some sample classes/interfaces
interface Something
{
    public function getName(): string;

    public function getHeaders(): array;

    public function getData(): array;
}

class IdClass
{
    const callOn = 'getTestResultX';
    protected $id;

    public function __construct($id, $fid = 12)
    {
        $this->id = $id;
    }

    public function getTestResult(FilterMe $filter)
    {
        echo "DemoClassGTR\n";
    }

    public function getTestResultX(FilterMe $filter)
    {
        echo "DemoClassGTRX1\n";
    }
}

class LidClass extends IdClass
{
    public function getTestResult(FilterMe $filter)
    {
        echo "DemoClassGTR\n";
    }

    public function getTestResultX(FilterMe $filter)
    {
        echo "DemoClassGTRX2\n";
    }
}

class TestClass
{
    public function __construct(Request $request2, $id)
    {
        echo "TestClassConstructor $id \n";
    }

    public function getTestResult(FilterMe $filter, $bid, LidClass $give)
    {
        echo "TestClassGTR \n";
    }

    public function getInterfaceResult(Something $something)
    {
        echo "TestInterface \n";
    }
}

class BlogReport implements Something
{
    public function getName(): string
    {
        return 'Blog report';
    }

    public function getHeaders(): array
    {
        return ['The headers go here'];
    }

    public function getData(): array
    {
        return ['The data for the report is here.'];
    }
}

class DemoClass
{
    const callOn = 'getTestResultX';

    public function __construct(Request $request)
    {
        echo "DemoClassConstructor \n";
    }

    public function getTestResult(FilterMe $filter)
    {
        echo "DemoClassGTR\n";
    }

    public function getTestResultX(FilterMe $filter)
    {
        echo "DemoClassGTRX\n";
    }
}

// Example 1
$return1 = container('dream')
            ->registerClass(TestClass::class, ['id' => 12])
            ->registerMethod(TestClass::class, 'getTestResult', [
                'give' => 346
            ])
            ->addDefinitions([
                'bid' => RestClass::class
            ])
            ->getReturn(TestClass::class);
// Example 2
$return2 = container('route')
            ->registerClass(TestClass::class, ['id' => 12])
            ->registerMethod(TestClass::class, 'getInterfaceResult', [
                'something' => BlogReport::class
            ])
            ->getReturn(TestClass::class);
```

**Lets see what is happening in our call:**

- Started the call with `container()`.
- Provided class name in **registerClass** method. The 2nd parameter is list of parameter passable to constructor
- Provided method name (2nd param) for the given class (1st param) along with passable parameter in 3rd in **registerMethod**
- **addDefinitions** taking variable to Class resolver by name
- **getReturn** just calling the class over pre-registered method (here _getTestResult_)

**What happened after we called?**

- It tried to initialize `TestClass::class`.
- Meanwhile it found Constructor parameters then Resolved The `Request` class. Afterwards it also passed the `id` parameter to $id
- Class initialized
- Afterwards,
  - Example 1: it checked for `getTestResult` method resolved the `FilterMe` class. Found `bid` parameter resolved it as `DemoClass::class`. Up next it got `LidClass` where it passed the `$give` in constructor.
  - Example 2: it checked for `getInterfaceResult` method. Found interface `Something`. Passed parameter there which also got filtered if the class implements & satisfies the interface.
- Method initialized. **getReturn** will return the found result from the method.

## Now lets look into our available methods

We use `container()` function for initialize (as you have seen above examples). Afterwards, you can pipe through till our finalizers are called. The containers are accessible till destructor (`unset()`) is called.

### Initialize

#### `container(string|Closure|array $closureOrClass = null, string $alias = 'inter_mix')`

#### `new Container(string $alias = 'inter_mix')`

#### `Container::instance(string $alias = 'inter_mix')`

Our main initializer. Optional second parameter `$alias` is actually a signature that is unique  and can be used (through piping) across till `unset()` called. (if `container()` function used) 1st parameter returns Container instance if empty. If Closure or Class name passed, related finalizers are initiated.

```php
/* 
1. if you check Examples (1 & 2) above, you will see we have used different aliases. 
Reason was Single class can only have one method registered at a time per Class. 
As we needed different method each time we have done it like that.
Though we can also do it like:
*/
$return1 = container('dream')
            ->registerClass(TestClass::class, ['id' => 12])
            ->registerMethod(TestClass::class, 'getTestResult', [
                'give' => 346
            ])
            ->addDefinitions([
                'bid' => RestClass::class
            ])
            ->getReturn(TestClass::class);
// as we are done calling can now modify method
$return2 = container('dream')
            ->registerMethod(TestClass::class, 'getInterfaceResult', [
                'something' => BlogReport::class
            ])
            ->callMethod(TestClass::class);
// No need to pass any param? initializing is simple (use either of below as you need):
container(['namespace\class','method']);
container(['namespace\class@method']);
container(closure());
/*
2. You can always keep registering and initializing under a container as long as it is alive.
3. If one of the container not needed any more, just call unset() at the end of chain & it will be dead.
*/
```

#### `registerClass(string $class, array $parameters = [])`
Takes class name in first parameter and in 2nd parameter it will take the parameters as associative/sequential arrays for passing into constructor. The keys should be same as the parameter name. if you don't need to pass any extra parameter in constructor, you can omit this.

#### `registerMethod(string $class, string $method, array $parameters = [])`
Class name, method name, parameters. if you need only Class initialized object or don't need to pass any extra parameter in method and have `callOn` set up in class, you can omit this.

#### `registerProperty(string $class, string $property, mixed $value = null)`
Register properties which will be resolved during the class resolution.

#### `registerClosure(string $closureAlias, Closure $function, array $parameters = [])`
Register a closure with custom alias (which can be used to call a specific closure later).

#### **Important**

You can define `const callOn` to let know the Container to resolve an specific method of that class. But it will be omitted if method is registered for that class using `registerMethod()`.

### Mix/Modify

#### `addDefinitions(array $definitions)`

Register definitions. Any parameter found matching the key name in `$definitions` will be resolved and injected through. It will be resolved in both constructor & method.

`$definitions` is an associative array formatted as:

```php
[
 'definition 1' => 'class reference / closure / any mixed value',
 ... => ...
]
```

#### `setOptions(bool $enableInjection = true, bool $enablePropertyResolution = true, bool $useAttributes = false, string $defaultMethod = null)`
- Setting `$enableInjection` to false will disable dependency injection.
- Setting `$enablePropertyResolution` to false will disable class property resolution
- Setting `$useAttributes` to true will enable attribute based injection on properties
- Setting `$defaultMethod` will make call to default method if no method is provided via `registerMethod` or `const callOn`

#### **Important**
Priority: `registerMethod()` >  `const callOn` > `$defaultMethod`

### Finalize / Call the chain

#### `call(string|Closure|callable $classOrClosure, string|bool $method = null)`
#### `getReturn(string $id)`
Execute the method (if applicable) & get the return after resolution. `$method` is optional here. If we need to call different method except the registered one can use this parameter. `getReturn()` will resolve by predefined entries.

#### PSR11 compliant (get(), has())

### Finalize / destroy
#### `unset()`
Destroys current instance. You can no longer pipe through and the instance will be destroyed (this is not undo, just erasing the service)
