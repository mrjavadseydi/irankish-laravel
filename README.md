# laravel iran kish payment package

a simple package for usnig irankish gateway.

پکیج درگاه پرداخت ایران کیش برای لاراول


## Installation

Use the composer to install irankish-laravel.

```bash
composer require javad/ir-kish
```
 publish config file

```bash
php artisan vendor:publish --provider=MJSeydi\iranKish\IranKishServiceProvider
```

## Usage

```php
use MJSeydi\iranKish\Facades\IranKish;

# getting the token from irankish 
# you must save $orderId in your database for verify the payment
$response = IranKish::getIranKishToken($Amount,$orderId);
if ($response["responseCode"] != "00") {
     # you have error!
}else{
     $token = $response['result']['token'];
     # in you view you must have a form like this
     /*
<form action="https://ikc.shaparak.ir/iuiv3/IPG/Index/" method="POST">
   <input type="hidden" name="tokenIdentity" value="{{$token}}" />
   <input type="submit" value="ورود به درگاه پرداخت" />
</form>
    */
}

```
## verify payment
```php
use MJSeydi\iranKish\Facades\IranKish;

# all parameter will send to you by POST method 
$response = IranKish::verifyPayment($request->verifySaleReferenceId,
$request->systemTraceAuditNumber, $request->token);
#your order id is on the $request->orderId
if ($response["responseCode"] != 0 && $response["responseCode"] != "00") {
     # you have error!
}else{
     echo "success";
}

```

## Contributing
Pull requests are welcome. For major changes, please open an issue first to discuss what you would like to change.

