<?php

namespace App\BusinessLayer;

use App\DTO\UserDTO;
use App\Mail\VerifyMail;
use App\Models\User;
use App\Models\UserProviderAcc;
use App\Models\VerifyUser;
use App\DTO\DatatableDTO;
use App\PresentationLayer\ResponseCreatorPresentationLayer;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Hash;
use Validator, DB, Carbon\Carbon;
use JWTAuth;
use Mail;
use File;

class UserBusinessLayer extends GenericBusinessLayer
{
    public function getUser(UserDTO $params)
    {
        try{
            if ($params->getIsForAPI() == 1) {
                $token = JWTAuth::getToken();
                $user = JWTAuth::toUser($token);

                $data = User::find($user->id);
            }else{
                $encryptedId = $params->getId();
                $decryptedId = decrypt($encryptedId);
                $data = User::find($decryptedId);
            }

            if ($data != null) {
                $response = new ResponseCreatorPresentationLayer(200, 'Data ditemukan!', $data);
            }else{
                $response = new ResponseCreatorPresentationLayer(500, 'data tidak ditemukan!', null);
            }
        }catch(\Exception $e){
            $response = new ResponseCreatorPresentationLayer(500, 'Terjadi kesalahan pada server', null);
        }

        return $response->getResponse();
    }

    public function actionSave(UserDTO $params)
    {
        try{
            DB::beginTransaction();

            if ($params->getIsForAPI() == 1) {
                $id = $params->getId();
                $cityId = $params->getCityId();
            }else{
                // $encryptedId = $params->getId();
                // $id = decrypt($encryptedId);
                // $encryptedCityId = $params->getCityId();
                // $cityId = decrypt($encryptedCityId);
                $id = $params->getId();
                $cityId = $params->getCityId();
            }

            $name = $params->getName();
            $email = $params->getEmail();
            $password = $params->getPassword();
            $phone = $params->getPhone();
            $phoneEmergency = $params->getPhoneEmergency();
            $gender = $params->getGender();
            $fcmToken = $params->getFcmToken();
            $birthDate = $params->getBirthDate();
            $registerType = $params->getRegisterType();

            $data = [
              'name' => $name,
              'email' => $email,
              'phone' => $phone,
              'phone_emergency' => $phoneEmergency,
              'gender' => $gender,
              'fcm_token' => $fcmToken,
              'city_id' => $cityId,
              'birth_date' => $birthDate,
              'register_type' => $registerType,
            ];

            if(!is_null($params->getPhoto())) {
                if ($params->getPhoto()) {
                    $checkedParams = [
                    'file' => $params->getPhoto()
                    ];
                    $rules = [
                        'file' => 'mimes:jpeg,bmp,png,gif,jpg|max:5000',
                    ];
                    $validator = Validator::make($checkedParams, $rules);
                    if ($validator->fails()) {
                        DB::rollback();
                        $error = $validator->errors()->first();
                        $response = new ResponseCreatorPresentationLayer(401, $error, null);
                        return $response->getResponse();
                    } else {
                        if (!file_exists('uploads/user/photo')) {
                            File::makeDirectory('uploads/user/photo', 0755, true);
                        }
                        
                        $destinationPath = 'uploads/user/photo';
                        $fileName = date('YmdHis') . '_' . $params->getPhoto()->getClientOriginalName();
                        $fileName = str_replace(' ', '_', $fileName);
                        $params->getPhoto()->move($destinationPath, $fileName);
                    }
                    $data['photo'] = config('config.app_url').'uploads/user/photo/'.$fileName;
                }else{
                    DB::rollback();
                    $response = new ResponseCreatorPresentationLayer(400, 'Silakan mengupload user photo', null);
                    return $response->getResponse();
                }
             }
             
            if(is_null($password) || $password == ""){
                $data = $data;
            }else{
                $data = array_merge($data, ['password' => Hash::make($password)]);
            }

            $rules = $this->rules();
            $rulesUpdate = $this->rulesUpdate();

            if(is_null($id)){
                $validator = Validator::make($data, $rules);
                if ($validator->fails()) {
                    $error = $validator->errors()->first();
                    $response = new ResponseCreatorPresentationLayer(404, $error, null);
                    return $response->getResponse();
                }

                if ($registerType == 0) {
                    $user = User::create($data);
                    $data = User::where('id', $user->id)->first();
                    
                    DB::commit();
                    $response = new responseCreatorPresentationLayer(200, 'Data berhasil disimpan!', $data);
                }else{
                    $user = User::create($data);

                    $getUser = User::where('id', $user->id)->first();
                    $data['remember_token'] = JWTAuth::fromUser($getUser);

                    $expDate = mktime(
                        date("H"), date("i"), date("s"), date("m") ,date("d")+3, date("Y")
                    );

                    $verifyUser = VerifyUser::create([
                        'user_id' => $user->id,
                        'token' => sha1(time()),
                        'expired' => date("Y-m-d H:i:s",$expDate)
                      ]);

                    $time = Carbon::now()->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s');
                    $data['verify_token_email'] =  $user->verifyUser->token;
                    $data['data'] = $data;   

                    Mail::send('email.verify', $data, function($message) use ($email, $data)  {
                        $message->from('acid@temancurhat.id', 'Registrasi Berhasil - Teman Curhat');

                        $message->to($email, $data['name'], $data['verify_token_email'])
                                ->subject('Registrasi Berhasil '.$data['name'].'- Teman Curhat');
                    });

                    DB::commit();

                    $response = new responseCreatorPresentationLayer(200, 'Data berhasil disimpan!', $data['data']);
                }
            }else{
                $validate = $this->validate($id, $email);

                if($validate['code'] == 200){
                    $validator = Validator::make($data,$rulesUpdate);
                    if ($validator->fails()) {
                        $error = $validator->errors()->first();
                        $response = new ResponseCreatorPresentationLayer(404, $error, null);
                        return $response->getResponse();
                    }
                }

                $input = collect($data)->filter()->all();

                User::find($id)->update($input);
                $dataUser = User::where('id', $id)->first();

                $response = new responseCreatorPresentationLayer(200, 'Data berhasil diperbarui!', $dataUser);
                DB::commit();
            }

        }catch (\Exception $e){
            DB::rollback();
            $response = new ResponseCreatorPresentationLayer(500, $e->getMessage(), $e->getMessage());
        }

        return $response->getResponse();
    }

    private function rules()
    {
        $rules = [
            'email' => 'required|unique:m_users',
            'phone' => 'required|unique:m_users',
            'city_id' => 'required|not_in:0',
        ];

        return $rules;
    }

    private function rulesUpdate()
    {
        $rules = [
            'phone' => 'required',
            'email' => 'required',
        ];

        return $rules;
    }

    public function logout()
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
           
            $response = new responseCreatorPresentationLayer(200, 'Berhasil Logout!', $dataUser);
        } catch (Exception $e) {
            $response = new ResponseCreatorPresentationLayer(500, 'Terjadi kesalahan pada server', null);
        }

        return $response->getResponse();
    }

    public function actionCheckLogin(UserDTO $params)
    {
        try{
            if(is_null($params->getEmail()) || $params->getEmail()==""){
                $response = new ResponseCreatorPresentationLayer(400, 'Silakan mengisi Email', null);
                return $response->getResponse();
            }

            if(is_null($params->getPassword()) || $params->getPassword()==""){
                $response = new ResponseCreatorPresentationLayer(400, 'Silakan mengisi password', null);
                return $response->getResponse();
            }

            $data = User::where('email', $params->getEmail())
                            ->first();


            if(is_null($data)){
                $response = new ResponseCreatorPresentationLayer(404, 'Data pengguna tidak ditemukan', null);
                return $response->getResponse();
            }else{
                 if($params->getisForApi() == 1){
                    $updateFcm = $this->actionUpdateFcm($params);
                    $data['remember_token'] = JWTAuth::fromUser($data);
                    $data['fcm_token'] = $updateFcm['data']->fcm_token;
                }
            }

            $loginType = filter_var($params->getEmail(), FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

            $loginData = [
                $loginType => $params->getEmail(),
                'password' => $params->getPassword(),
            ];

            if (!auth()->attempt($loginData)) {
                $response = new ResponseCreatorPresentationLayer(401, 'Password tidak sesuai', null);
                return $response->getResponse();
            }

            $response = new ResponseCreatorPresentationLayer(200, 'Login berhasil', $data);
        }catch (\Exception $e){
            $data = null;
            $response = new ResponseCreatorPresentationLayer(500, 'Terjadi kesalahan pada server', null);
        }

        return $response->getResponse();
    }


    public function actionCreateNewInstance()
    {
        try{
            $data = new User();
            $response = new ResponseCreatorPresentationLayer(200, 'Data berhasil dibuat', $data);
        }catch (\Exception $e){
            $data = null;
            $response = new ResponseCreatorPresentationLayer(500, 'Terjadi kesalahan pada server', $data);
        }

        return $response->getResponse();
    }


    private function validate($id, $email)
    {
          $checkUser = User::where('id','<>',$id)->where('email','=',$email);

          if ($checkUser->count() > 0) {
              $response = new ResponseCreatorPresentationLayer(500, 'Data has been already taken', null);
          }else{
              $response = new ResponseCreatorPresentationLayer(200, 'Data tersedia!', null);
          }
          return $response->getResponse();
    }


    public function actionUpdateFcm(UserDTO $params)
    {
        try {
            $email = $params->getEmail();

            $data = User::where('email', $email)->first();
            $data->fcm_token = $params->getFcmToken();

            $data->save();

            $response = new ResponseCreatorPresentationLayer(200, 'FCM Berhasil di Update!', $data);
        } catch (Exception $e) {
            $response = new ResponseCreatorPresentationLayer(500, 'Terjadi kesalahan pada server', null);
        }

        return $response->getResponse();
    }

    public function handleProviderCallback(UserDTO $params)
    {
        try{
            DB::beginTransaction();

            $authUser = UserProviderAcc::where('provider_id', $params->getProviderId())->first();

            if ($authUser) {
                $data = $authUser;
                $data = User::find($authUser->user_id);
                $data['remember_token'] = JWTAuth::fromUser($data);
            }else{
                $checkUser = User::where('email', $params->getEmail())->first();

                $provider = $params->getProvider();
                $providerId = $params->getProviderId();

                if ($checkUser) {
                    UserProviderAcc::create([
                        'user_id'     => $checkUser->id,
                        'provider' => $provider,
                        'provider_id' => $providerId
                    ]);

                    $data = User::find($checkUser->id);
                    $data['remember_token'] = JWTAuth::fromUser($data);
                }else{
                    $name = $params->getName();
                    $email = $params->getEmail();
                    $phone = $params->getPhone();
                    $fcmToken = $params->getFcmToken();
                    $birthDate = $params->getBirthDate();

                    //buat akun
                    $data = [
                      'name' => $name,
                      'email' => $email,
                      'phone' => $phone,
                      'gender' => 'L',
                      'fcm_token' => $fcmToken,
                      'city_id' => 3173,
                      'birth_date' => $birthDate,
                    ];

                    if ($provider == 'google') {
                       $data['register_type'] = 2;
                    }else if ($provider == 'facebook') {
                       $data['register_type'] = 3;
                    }

                    if(!is_null($params->getPhoto())) {
                        $fileName = "uploads/user/photo/"."$name"."_".time().".jpg"; 

                        file_put_contents(
                            $fileName, 
                            file_get_contents($params->getPhoto())
                        );

                        $data['photo'] = config('config.app_url').$fileName;
                    }

                    $user = User::create($data);
                    $data = User::find($user->id);
                    $data['remember_token'] = JWTAuth::fromUser($data);

                    UserProviderAcc::create([
                        'user_id'     => $user->id,
                        'provider' => $provider,
                        'provider_id' => $providerId
                    ]);
                }

                DB::commit();
            }

            if ($data != null) {
                $response = new ResponseCreatorPresentationLayer(200, 'Data ditemukan!', $data);
            }else{
                DB::rollback();
                $response = new ResponseCreatorPresentationLayer(500, 'Akun belum terhubung!', null);
            }
        }catch(\Exception $e){
            DB::rollback();
            $response = new ResponseCreatorPresentationLayer(500, 'Terjadi kesalahan pada server', null);
        }

        return $response->getResponse();
    }

    public function forgetPassword(UserDTO $params)
    {
        $email = $params->getEmail();

        if(is_null($email) || $email == ""){
            $response = new ResponseCreatorPresentationLayer(404, 'Parameter email tidak tersedia', null);
            return $response->getResponse();
        }

        $activeUser = User::where(['email' => $email])->first();

        if(!is_null($activeUser)){
            $expDate = Carbon::now()->addDays(2);

            $dataReset = [
                'user_id' => $activeUser->id,
                'token' => md5($email),
                'expired' => $expDate
            ];

            DB::table('password_resets')->insert($dataReset);

            $data['title'] = "Reset Password";
            $data['url'] = config('config.app_url')."/reset-password/".$dataReset['token'];
            $data['dataUser'] = $activeUser;

            Mail::send('email.reset', $data, function($message) use ($email, $activeUser)  {
                $message->from('im.acidopal@gmail.com', 'Reset Password - Teman Curhat');

                $message->to($email, $activeUser->name)
                        ->subject('Reset Password '.$activeUser->name.'- Teman Curhat');
            });

            $response = new ResponseCreatorPresentationLayer(200, 'Permintaan reset password berhasil dikirim silahkan cek email!', $activeUser);

            return $response->getResponse();
        }else{
            $response = new ResponseCreatorPresentationLayer(404, 'Email tidak terdaftar!', null);
            return $response->getResponse();
        }
    }

    public function isAnonym(UserDTO $params)
    {
        try{
            if ($params->getIsForAPI() == 1) {
                $token = JWTAuth::getToken();
                $user = JWTAuth::toUser($token);

                $data = User::find($user->id);

                if ($data->is_anonym == false) {
                    $data->update(['is_anonym' => true]);
                }else{
                    $data->update(['is_anonym' => false]);
                }

                $data['province_id'] = $data->getCity->province_id;
            }

            if ($data != null) {
                $response = new ResponseCreatorPresentationLayer(200, 'Data ditemukan!', $data);
            }else{
                $response = new ResponseCreatorPresentationLayer(500, 'data tidak ditemukan!', null);
            }
        }catch(\Exception $e){
            $response = new ResponseCreatorPresentationLayer(500, 'Terjadi kesalahan pada server', null);
        }

        return $response->getResponse();
    }

    public function actionDatatable(DatatableDTO $params)
    {
        try{
            $columDescriptions = [
                0 => 'no',
                1 => 'name',            
                2 => 'email',            
                3 => 'phone',            
                4 => 'photo',            
                5 => 'info',            
                6 => 'opsi'
            ];
    
            $limit = $params->getLimit();            
            $start = $params->getStart();            
            $order = $columDescriptions[$params->getOrder()];
            $dir = $params->getDir();
            $search=$params->getSearch();
            $draw=$params->getDraw();

            if ($params->getFrom() != null) {
                $getFrom = $params->getFrom();
                $from = decrypt($getFrom);
            }

            $numberOfRows = User::count();

            $totalFiltered = $numberOfRows;
    
            if(empty($search)){
                $userData = User::offset($start)
                                    ->limit($limit)
                                    ->orderBy('id', 'desc')
                                    ->get();
            }else{
                $userData =  User::where('name', 'LIKE',"%{$search}%")
                                    ->offset($start)
                                    ->limit($limit)
                                    ->orderBy('id', 'desc')
                                    ->get();


                $totalFiltered =  User::where('name', 'LIKE',"%{$search}%")
                                         ->count();
            }   

            $data = [];
            if(count($userData) > 0){
                foreach($userData as $num => $user){
                    $currentData = [];
                    $currentData['no'] = $num+1;
                    $currentData['name'] = $user->name;                    
                    $currentData['email'] = $user->email;                    
                    $currentData['phone'] = $user->phone;                    
                    $currentData['photo'] = ($user->photo) ? '<img src="'.asset($user->photo).'" alt="'.$user->name.'"  width="50px">' : '-';                                 
                    $currentData['info'] = ''
                        // 'Status : '.$user->is_active.'<br>'.
                        // 'Verfied : '.$user->is_verified.'<br>'
                    ;                    
                    $currentData['opsi'] = "
                    <a href=".url('/user/form?id='.encrypt($user->id)).".  class='btn btn-warning mr-1 mb-1 waves-effect waves-light'><i class='fa fa-edit'></i> Edit</a>

                    <a onclick=deleteData('".encrypt($user->id)."') class='btn btn-danger mr-1 mb-1 waves-effect waves-light'><i class='fa fa-trash'></i> Hapus</a>";

                    $data[] = $currentData;
                }
            }

            $jsonData = [
                "draw"            => intval($draw),
                "recordsTotal"    => intval($numberOfRows),
                "recordsFiltered" => intval($totalFiltered),
                "data"            => $data
            ];

            $response = new ResponseCreatorPresentationLayer(200, 'Data ditemukan!', $jsonData);

        }catch(\Exception $e){
            $jsonData = [
                "draw"            => 0,
                "recordsTotal"    => 0,
                "recordsFiltered" => 0,
                "data"            => []
            ];

            $response = new ResponseCreatorPresentationLayer(200, 'Data ditemukan!', $e->getMessage());
        }

        return $response->getResponse();
    }


    public function actionDelete(UserDTO $params)
    {
        try{
            try {
                $id = decrypt($params->getId());
            } catch (DecryptException $e) {
                $id = 0;
            }

            $data = User::find($id);
            if(is_null($data)){
                $response = new ResponseCreatorPresentationLayer(404, 'Data perusahaan tidak ditemukan', $data);
                return $response->getResponse();
            }

             if (!is_null($data->photo)) {
                if(file_exists($data->photo)){ unlink($data->photo);}
             }

            $data->delete();
            $response = new ResponseCreatorPresentationLayer(200, 'Data berhasil dihapus', null);

        }catch(\Exception $e){
            $response = new ResponseCreatorPresentationLayer(500, 'Operation is not allowed! Data is in used by another entities ', null);
        }

        return $response->getResponse();
    }
}
