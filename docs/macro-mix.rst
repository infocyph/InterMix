# Inject method in a class, Dynamically
Depending on several scenario, we may need to add methods inside a class dynamically and that is where it comes in play.

# Lets see some example
Just simply add the trait in your desired class and see the magic:

```php
class House {
 use MacroMix;
 protected $color = 'gold';
}

$houseClass = new House();

// Inject a method
$houseClass::register('paint', function() {
   return $this->color;
});

// Now use it
$houseClass->paint(); // Output: gold
```

Lets' check another one alike,

```php
// Inject a method
$houseClass::register('dot', function(... $strings) {
   return implode('.', $strings);
};

// Now use it
$houseClass->dot('abc', 'def', 'ghi'); // Output: abc.def.ghi
```

# Lets advance more
Instead of function now lets push a class:

```php
$lemonade = new class() {
    public function lemon()
    {
       return function() {
          return 'Squeeze Lemon';
       };
    }

    public function water()
    {
       return function() {
          return 'Add Water';
       };
    }
}

// lets mix
$houseClass::mix($lemonade);

// Now all those method (lemon & water) is available in `houseClass` as well
$houseClass->lemon() // get: Squeeze Lemon
```
# Caution
It uses `__call` & `__callStatic` magic methods. Be careful if your class already using them. It will end up in conflict.