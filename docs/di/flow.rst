.. _di.flow:

=============
Feature flow
=============

In this section you will know what happens under the hood (if **autowiring** is enabled).

Main Process steps
------------------

After setting all the options/registering what ever we need we just execute ``get`` or ``getReturn`` or ``call``.

* ``get`` or ``getReturn`` calls ``call`` under the hood. Only difference is they cache everything.
* If it is definition return the resolved result
* If it is function

    * resolves all the parameters
    * execute & return the result

* If it is class

    * resolve parameters in the constructor
    * resolve properties
    * resolve properties with Attributes (based on set options & if applicable)
    * Select method based on priority (check below)
    * gather method Attribute (based on set options & if applicable)
    * gather parameter Attribute (based on set options & if applicable)
    * resolve method parameters (will also use attributes found in previous step)
    * execute & return the result

Parameter resolution steps
---------------------------

The process goes through every parameter of the in-progress method (this also include ``__constructor()``).

* Get all the supplied parameters for that method (via ``registerMethod()`` or for constructor ``registerClass()``)
* Get all the parameter assigned via attribute (if ``methodAttribute`` is enabled)

.. note::
    | Parameter from attribute will have less priority.
    | Only attributes marked with class ``Infuse()`` will be counted (on method or argument)
    | On ``__constructor()``, attributes will be ignored.

* Resolve named parameters first then the others
* Resolve value by following order (whichever available)

    * definition
    * resolvable class (also if named parameter is injectable)
    * direct value placement from available supplies (supplied as key-value/associative array)
    * associative attributes declared under doc-block
    * direct value placement from available supplies (supplied as value/non-associative array)
    * attributes declared with arguments

.. code:: php

    class InterMix
    {
        /**
         * Resolving constructor
         * 1. Promoted property & other parameters will be resolved here
         * 2. ClassC will be resolved & injected into $classC
         * 3. $myString will be resolved with either definition or value set via 'registerClass'
         * 4. Attributes are not supported on constructor
         *
         * @param ClassC $classC
         * @param string $myString
         */
        public function __construct(
            protected ClassC $classC,
            protected string $myString
        ) {
            // doing some operations here
        }

        /**
         * Resolving method
         * 1. 'ClassModel' class will be resolved into $classModel
         * 2. If any parameter with the name 'classModel' is delivered, the value will be sent to
         *    constructor of 'ClassModel' (parameter to Class binding)
         * 3. If 'parameterA' don't have any parameter supply in form of Key => Value (associative)
         *    it will be resolved by calling time() function
         * 4. If 'parameterB' don't have any supply in form of value (non-associative) / Key => Value
         *    (associative) the 'db.host' will be resolved from definition (same steps as of property)
         * 5. Any leftover supply parameter(s) will be resolved in variadic $parameterC
         * 6. If variadic is not present and supply parameters have values left, it will be ignored
         *
         * @param ClassModel $classModel
         * @param string $parameterA
         * @param string $parameterB
         * @param ...$parameterC
         * @return void
         */
        #[Infuse(parameterA: 'time')]
        public function resolveIt(
            ClassModel $classModel,
            string $parameterA,
            #[Infuse('db.host')] string $parameterB,
            ...$parameterC
        ) {
            // doing some operations here
        }
    }

Property resolution steps
-------------------------

In between **constructor** & **method** resolution, class properties are resolved (if enabled in option).

* resolve current class first then also the parent class (if available)
* resolve if property supplied via ``registerProperty()``
* check if initiated via class ``Infuse()``
* resolve if type hint indicates any resolvable class
* if found in definition list, resolve
* if any function exists with given name, resolve

Check below example for further understanding:

.. code:: php

    class InterMix
    {
        /**
         * A normal property, got no attribute.
         * > Will do nothing unless property is set by 'registerProperty()'
         *
         * @var string
         */
        private string $nothing;

        /**
         * A property labeled with 'Infuse' class and no parameter
         * > ClassA will be resolved and injected in $classA
         *
         * @var ClassA
         */
        #[Infuse]
        private ClassA $classA;

        /**
         * A property labeled with 'Infuse' class with keyless parameter
         * > Will resolve it using set definitions.
         * > Will pick first parameter (inside 'Infuse') only (applicable for any property resolution)
         * > In case of type mismatch, error will be thrown (applicable for any property resolution)
         *
         * @var string
         */
        #[Infuse('db.host')]
        private string $something;

        /**
         * A property labeled with 'Infuse' class with key-value paired parameter
         * > It will call 'strtotime()' with 'yesterday' as first parameter
         * > To send more parameter, send an array as value like in below case ['yesterday', 1678786990]
         *
         * @var int
         */
        #[Infuse(strtotime: 'yesterday')]
        private int $yesterday;
    }

Method Selection
----------------

When container scans through the classes, it selects method using below priority:

* Method already provided, using ``call()``
* Look for method, registered via ``registerMethod()``
* Method provided via ``callOn`` constant
* Method name found via ``defaultMethod``

If none found after above steps, method won't be resolved.
