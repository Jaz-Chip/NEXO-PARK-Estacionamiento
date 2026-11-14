<?php
// Run only on the existing configured MySQL database. All verification rows roll back.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\{User,Client,Vehicle,Space,Subscription,Floor,Sector,Rate,VehicleType};
if (DB::connection()->getDriverName() !== 'mysql') throw new RuntimeException('MySQL is required.');
// Like Laravel HTTP tests, suppress CSRF only in this CLI harness; browser verifies real CSRF separately.
$app['env']='testing';
config(['session.driver'=>'array','cache.default'=>'array']);
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
$admin=User::whereHas('roles',fn($q)=>$q->where('slug','administrador'))->where('is_active',true)->firstOrFail();
$assertions=0;
function check(bool $ok,string $message): void {global $assertions; if (!$ok) throw new RuntimeException($message); $assertions++;}
function ajax(string $method,string $path,array $data=[],int $status=200): array {
 global $app,$kernel;
 $request=Illuminate\Http\Request::create($path,$method,$data,[],[],['HTTP_ACCEPT'=>'application/json','HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest']);
 $response=$kernel->handle($request);
 check($response->getStatusCode()===$status,$method.' '.$path.' expected '.$status.' got '.$response->getStatusCode().' '.substr($response->getContent(),0,500));
 return json_decode($response->getContent(),true) ?? [];
}
$tag='VERIFY-'.bin2hex(random_bytes(4));
DB::beginTransaction();
$failed = false;
try {
 Auth::setUser($admin);
 $items=[];
 $make=function($slug,$data) use(&$items) {
  $result=ajax('POST','/'.$slug,$data,201);
  $id=$result['data']['id'];
  ajax('PUT',"/$slug/$id",$data);
  ajax('PATCH',"/$slug/$id/estado",['is_active'=>0]);
  ajax('PATCH',"/$slug/$id/estado",['is_active'=>1]);
  $items[$slug]=$id;
  echo "$slug: create, update, deactivate, activate OK\n";
  return $id;
 };
 $type=$make('tipos-vehiculo',['name'=>$tag.' Auto']);
 $client=$make('clientes',['name'=>$tag.' Persona','document'=>$tag,'email'=>'verify@example.test','phone'=>'12345678']);
 $floor=$make('pisos',['name'=>$tag.' Piso']);
 $sector=$make('sectores',['name'=>$tag.' Sector','floor_id'=>$floor]);
 $vehicle=$make('vehiculos',['plate'=>$tag,'vehicle_type_id'=>$type,'client_id'=>$client,'brand'=>'Verificación','model'=>'Modelo','color'=>'Azul']);
 $space=$make('espacios',['code'=>$tag,'floor_id'=>$floor,'sector_id'=>$sector,'vehicle_type_id'=>$type,'status'=>'available']);
 $rate=$make('tarifas',['name'=>$tag.' Mensual','vehicle_type_id'=>$type,'unit'=>'month','amount'=>'100.00']);
 $subscription=$make('abonados',['client_id'=>$client,'vehicle_id'=>$vehicle,'rate_id'=>$rate,'space_id'=>$space,'starts_on'=>today()->toDateString(),'ends_on'=>today()->addMonth()->toDateString(),'amount'=>'100.00']);
 check(Subscription::findOrFail($subscription)->vehicle->client->id===$client,'Client/vehicle/subscription relation');
 check(Space::findOrFail($space)->sector->floor_id===$floor,'Space/sector/floor relation');
 check(Subscription::findOrFail($subscription)->rate->vehicle_type_id===$type,'Rate/type relation');
 foreach($items as $slug=>$id) {
  $list=ajax('GET',"/$slug/datos");
  check(in_array($id,array_column($list['data'],'id')),"$slug record missing from list");
  $page=$kernel->handle(Illuminate\Http\Request::create('/'.$slug));
  check($page->getStatusCode()===200,"$slug page failed");
 }
 ajax('POST','/vehiculos',['plate'=>strtolower($tag),'vehicle_type_id'=>$type],422);
 ajax('POST','/clientes',['name'=>'Otro','document'=>$tag],422);
 ajax('POST','/tarifas',['name'=>'Invalid','vehicle_type_id'=>$type,'unit'=>'fraction','amount'=>5],422);
 foreach(['minute','hour','fraction'] as $unit) {
  $data=['name'=>$tag.' '.$unit,'vehicle_type_id'=>$type,'unit'=>$unit,'amount'=>'1.50'];
  if($unit==='fraction')$data['fraction_minutes']=15;
  ajax('POST','/tarifas',$data,201);
 }
 ajax('PATCH',"/espacios/$space/estado",['is_active'=>0],422);
 ajax('PATCH',"/abonados/$subscription/estado",['is_active'=>0]);
 Space::findOrFail($space)->update(['status'=>'occupied']);
 ajax('PATCH',"/espacios/$space/estado",['is_active'=>0],422);
 Space::findOrFail($space)->update(['status'=>'available']);
 ajax('PATCH',"/espacios/$space/estado",['is_active'=>0]);
 $view=app(App\Http\Controllers\DashboardController::class)()->getData();
 check($view['available']===Space::where('status','available')->where('is_active',true)->count(),'Dashboard includes inactive spaces');
 foreach(['supervisor','operador'] as $role) {
  Auth::setUser(User::whereHas('roles',fn($q)=>$q->where('slug',$role))->firstOrFail());
  foreach($items as $slug=>$id) {
   ajax('GET',"/$slug/datos");
   if($role==='supervisor' || in_array($slug,['tipos-vehiculo','pisos','sectores','tarifas'])) {
    ajax('POST','/'.$slug,[],403);
    ajax('PUT',"/$slug/$id",[],403);
    ajax('PATCH',"/$slug/$id/estado",['is_active'=>0],403);
   }
  }
 }
 echo "MySQL: $assertions checks passed.\n";
} catch (Throwable $error) {
 $failed = true;
 fwrite(STDERR, $error->getMessage().PHP_EOL);
} finally {
 DB::rollBack();
 echo "Verification transaction rolled back; no test rows retained.\n";
}
exit($failed ? 1 : 0);

