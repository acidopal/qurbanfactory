<?php

namespace App\Http\Controllers\User;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\BusinessLayer\UserBusinessLayer;
use App\DTO\UserDTO;

class UserController extends Controller
{
    private $userBusinessLayer;

    public function __construct()
    {
        $this->userBusinessLayer = new UserBusinessLayer();
    }

    public function loginAdmin(Request $request)
    {
        if ($request->session()->exists('activeUser')) {
            return redirect('/dashboard');
        }

        $params = [
            'title' => 'Login'
        ];
        
        return view('admin.login.index', $params);
    }

    public function validateLogin(Request $request)
    {
        try{
            $email = $request->input('email');
            $password = $request->input('password');

            $params = new UserDTO();
            $params->setEmail($email);
            $params->setPassword($password);

            $result = $this->userBusinessLayer->actionCheckLogin($params);

            if($result['code'] == 200){
               $request->session()->put('activeUser', $result['data']);
               return '
                 <script>
                     toastr.success("'.$result['message'].'", "Berhasil!", 
                        { 
                            "showMethod": "fadeIn", 
                            "hideMethod": "fadeOut", 
                            timeOut: 2000, 
                            positionClass: "toast-bottom-right", 
                            containerId: "toast-bottom-right"
                         }
                     );
                     reload(1000);
                </script>'; 
            }else{
                 return '
                 <script>
                     toastr.error("'.$result['message'].'", "Error !", 
                        { 
                            "showMethod": "fadeIn", 
                            "hideMethod": "fadeOut", 
                            timeOut: 2000, 
                            positionClass: "toast-bottom-right", 
                            containerId: "toast-bottom-right"
                         }
                     );
                </script>'; 
            }
        }catch(\Exception $e){
            return "<div class='alert alert-danger'>Terjadi kesalahan pada server <br>".$e->getMessage()."</div>";
        }
    }

    public function logout(Request $request)
    {
        $request->session()->flush();
        return redirect('/');
    }
}
