Pass a function to it, it will cache the output as long as you need and deliver back.

We have 2 functions `memoize()` & `remember()` which is different from each other on how long the cache will be served.

# Let's understand with example
Both of our function accepts callable but in a different way.

#### Example
```php
class MySpecialClass
{
    public function __construct()
    {
        // do something here
    }

    public function method1()
    {
        return memoize(function () {
            return microtime(true);
        });
    }

    public function method2()
    {
        return remember($this, function () {
            return microtime(true);
        });
    }

    public function method3()
    {
        return [
            $this->method1(),
            $this->method2()
        ];
    }
}
```

#### Functions

#### `memoize(callable $callable = null, array $parameters = [])`
Just pass in a Closure or any callable on first parameter. In 2nd parameter you should pass parameters if the callable require any parameter. On above example it doesn't matter how many time you call **(new MySpecialClass())->method1()** or **$classInstance->method1()** (**$classInstance = new MySpecialClass()**) it will always return the same.

#### `remember(object $classObject = null, callable $callable = null, array $parameters = [])`
Almost same as previous function but it takes class object (`$this` or any other class instance) as first parameter & 2 more as parameter with same signature. The difference is, If, any time your class object is garbage collected or destroyed the memory will be gone, automatically. It is memory safe due to the fact that, it removes the data when it no longer needed.