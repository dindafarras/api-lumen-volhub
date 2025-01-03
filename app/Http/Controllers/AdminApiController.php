<?php
namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use App\Models\Admin;
use App\Models\User;
use App\Models\Mitra;
use App\Models\Kategori;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Date;

use Illuminate\Support\Facades\Validator;

use Illuminate\Support\Facades\Redis;

use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;


class AdminApiController extends Controller
{
    
    // Login Admin
    public function loginAdmin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $username = $request->input('username');
        $credentials = $request->only('username', 'password');

        // Periksa apakah pengguna sedang diblokir
        $attemptKey = "login:attempts:$username";
        $blockKey = "login:blocked:$username";

        if (Redis::exists($blockKey)) {
            $ttl = Redis::ttl($blockKey);
            return response()->json([
                'success' => false,
                'message' => "Too many login attempts. Please try again in $ttl seconds",
            ], 429);
        }

        try {
            // Cek apakah token sudah ada di Redis
            $redisKey = "admin:token:$username";
            if (Redis::exists($redisKey)) {
                $token = Redis::get($redisKey);
                
                $admin = Admin::where('username', $username)->first();
                if (!$admin) {
                    return response()->json([
                        'success' => false,
                        'message' => 'User not found',
                    ], 404);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Login successful (Redis)',
                    'token' => $token,
                    'data' => [
                        'id' => $admin->id, // Ambil ID user dari model
                        'username' => $admin->username,
                    ]
                ], 200);
            }

            // Login dan buat token baru
            $admin = Admin::where('username', $username)->first();
            if (!$admin || !Hash::check($credentials['password'], $admin->password)) {
                // Tambah jumlah percobaan login
                $attempts = Redis::incr($attemptKey);
                Redis::expire($attemptKey, 3600);

                if ($attempts >= 5) {
                    Redis::setex($blockKey, 300, true);
                    Redis::del($attemptKey);
                    return response()->json([
                        'success' => false,
                        'message' => 'Too many login attempts. You are blocked for 5 minutes.',
                    ], 429);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Login failed, incorrect username or password',
                    'attempts_left' => 5 - $attempts,
                ], 401);
            }

            // Buat token baru untuk mitra
            $token = JWTAuth::claims([
                'username' => $username,
                'iat' => time(),
            ])->fromUser($admin);

            // Simpan token ke Redis
            Redis::setex($redisKey, 3600, $token);

            return response()->json([
                'success' => true,
                'message' => 'Login successful (new token created)',
                'token' => $token,
                'data' => [
                    'id' => $admin->id, // Ambil ID user dari model
                    'username' => $admin->username,
                ]
            ], 200);
        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create token',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Profile Admin
    public function profile() 
    {
        $adminId = auth('admin')->id();
    
        // Key Redis
        $key = "admin:profile:{$adminId}";

        // Periksa data di Redis
        $adminData = Redis::get($key);
    
        if (!$adminData) {
            $admin = Admin::find($adminId); // Data dari MySQL
            if (!$admin) {
                return response()->json([
                    'success' => false,
                    'message' => "Nope, we couldn't find that ID. It's either gone or never existed"
                ], 404);
            }
    
            // Simpan ke Redis (3600 detik)
            Redis::setex($key, 3600, json_encode($admin));

            return response()->json([
                'success' => true,
                'message' => 'The admin data has been successfully retrieved',
                'data' => $admin
            ]);
        } else {
            $admin = json_decode($adminData, true);
            return response()->json([
                'message' => 'The volunteer data has been successfully retrieved (Redis)',
                'data' => $admin
            ], 200);
        }
    }
    
    public function editProfile(Request $request)
    {
        $idAdmin = $request->query('idAdmin');
        
        try {
            $authenticatedidAdmin = auth('admin')->user()->id;
            if ($authenticatedidAdmin != $idAdmin) {
                return response()->json([
                    'success' => false,
                    'message' => 'you do not have permission',
                ], 403);
            }

            $admin = Admin::find($idAdmin);

            if (!$admin) {
                return response()->json([
                    'success' => false,
                    'message' => "Nope, we couldn't find that ID. It's either gone or never existed",
                ], 404);
            }

            // Validasi input
            $validator = Validator::make($request->all(), [
                'username' => 'nullable|max:255',
                'password' => [
                    'nullable',
                    'min:8',
                    'max:255',
                    'regex:/[A-Z]/',    
                    'regex:/[0-9]/',    
                    'regex:/[\W_]/',    
                ],
            ], [
                'username.max' => 'Username cannot be more than 255 character',
                'password.min' => 'Password must be at least 8 characters long',
                'password.max' => 'Password cannot be more than 255 characters',
                'password.regex' => 'Password must contain at least one uppercase letter, one number, and one symbol',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $admin->username = $request->username ?? $admin->username;

            if ($request->filled('password')) {
                $admin->password = Hash::make($request->password);
            }

            $admin->save();

            Redis::del("admin:profile");

            return response()->json([
                'success' => true,
                'message' => 'Data successfully updated',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update admin profile',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Kelola Kategori
    public function category () 
    {
        $key = "admin:category:all";
        $categoryData = Redis::get("$key");
    
        // Kalau tidak ada di Redis
        if (!$categoryData) {
            $category = Kategori::all();

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Category never existed'
                ], 404);
            }
    
            // Simpan ke Redis (60 detik)
            Redis::setex("$key", 3600, json_encode($category));
            return response()->json([
                'success' => true,
                'message' => 'Data successfully retrieved',
                'data' => $category
            ]);
        } else {
            // Ambil data dari Redis
            $category = json_decode($categoryData);
            return response()->json([
                'success' => true,
                'message' => 'Data successfully retrieved (Redis)',
                'data' => $category
            ]);
        }
    }

    public function addCategory (Request $request) 
    {
        try {
            $category = new Kategori;

            // Validasi input
            $validator = Validator::make($request->all(), [
                'nama_kategori' => 'required|max:50',
            ], [
                'nama_kategori.required' => 'Category is required',
                'nama_kategori.max' => 'Category cannot be more than 50 characters',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $category->nama_kategori = $request->nama_kategori;
            $category->save();

            // Bersihkan cache daftar kategori di Redis
            Redis::del('admin:category:all');

            return response()->json([
                'success' => true,
                'message' => 'New category successfully added',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add new category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function editCategory (Request $request) 
    {
        try {
            $idCategory = $request->query('idCategory');
            
            $category = Kategori::find($idCategory);

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Category not found'
                ], 404);
            }

            // Validasi input
            $validator = Validator::make($request->all(), [
                'nama_kategori' => 'required|max:50',
            ], [
                'nama_kategori.required' => 'Category is required',
                'nama_kategori.max' => 'Category cannot be more than 50 characters',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $category->nama_kategori = $request->nama_kategori;
            $category->save();

            Redis::del('admin:category:all');

            return response()->json([
                'success' => true,
                'message' => 'Category successfully edited',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to edit category',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteCategory(Request $request) 
    {
        try {
            $idCategory = $request->query('idCategory');
            
            $category = Kategori::find($idCategory);

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Category not found'
                ], 404);
            }

            $category->delete();

            Redis::del('admin:category:all');

            return response()->json([
                'success' => true,
                'message' => 'Category successfully deleted'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed delete category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Kelola User
    public function user()
    {
        try {
            $key = "user:all";
            $userData = Redis::get("$key");

            if (!$userData) {
                $allUser = User::select('id', 'username', 'nama_user', 'email_user')->get();

                if (!$allUser) {
                    return response()->json([
                        'success' => false,
                        'message' => 'User not found'
                    ], 404);
                }
        
                // Simpan ke Redis (60 detik)
                Redis::setex($key, 3600, json_encode($allUser));
                return response()->json([
                    'success' => true,
                    'message' => 'Data successfully retrieved',
                    'data' => $allUser
                ]);
            } else {
                // Ambil data dari Redis
                $allUser = json_decode($userData);
                return response()->json([
                    'success' => true,
                    'message' => 'Data successfully retrieved (Redis)',
                    'data' => $allUser
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user data',
                'error' => $e->getMessage()
            ], 200);
        }
    }

    public function detailUser(Request $request)
    {
        try{
            $userId = $request->query('userId');
            
            $key = "admin:DetailUser:{$userId}";
            $detailUserData = Redis::get($key);

            if (!$detailUserData) {
                $detailUser = User::find($userId);

                if (!$detailUser) {
                    return response()->json([
                        'success' => false,
                        'message' => 'User not found'
                    ], 404);
                }

                Redis::setex($key, 3600, $detailUser->toJson());
                return response()->json([
                    'success' => true,
                    'message' => 'Data successfully retrieved',
                    'data' => $detailUser
                ], 200);
            } else {
                $detailUser = json_decode($detailUserData);
                return response()->json([
                    'success' => true,
                    'message' => 'Data successfully retrieved (Redis)',
                    'data' => $detailUser
                ], 200);
            }
        } catch(\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user data',
                'error' => $e->getMessage()
            ], 200);
        }
    }

    // Kelola Mitra
    public function mitra()
    {
        try {
            $key = "admin:allMitra:all";
            $mitraData = Redis::get($key);

            if(!$mitraData){
                $allMitra = Mitra::select('id_mitra', 'username', 'nama_mitra', 'email_mitra')->get();

                if(!$allMitra){
                    return response()->json([
                        'success' => false,
                        'message' => 'Employer not found'
                    ], 404);
                }
                Redis::setex($key, 3600, json_encode($allMitra));
                
                return response()->json([
                    'success' => true,
                    'message' => 'Data successfully retrieved',
                    'data' => $allMitra
                ], 200);
            } else {
                $allMitra = json_decode($mitraData);
                return response()->json([
                    'success' => true,
                    'message' => 'Data successfully retrieved (Redis)',
                    'data' => $allMitra,
                ],200);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function detailMitra(Request $request)
    {
        try {
            $employerId = $request->query('employerId');
            
            $key = "admin:detailMitra:{$employerId}";
            $detailMitraData = Redis::get($key);

            if(!$detailMitraData) {
                $detailMitra = Mitra::find($employerId);

                if (!$detailMitra) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Employer not found'
                    ], 404);
                }

                Redis::setex($key, 3600, $detailMitra->toJson());
                return response()->json([
                    'success' => true,
                    'message' => 'Data successfully retrieved',
                    'data' => $detailMitra
                ], 200);
            } else {
                $detailMitra = json_decode($detailMitraData);
                return response()->json([
                    'success' => true,
                    'message' => 'Data successfully retrieved',
                    'data' => $detailMitra
                ], 200);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user data',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function logout(Request $request)
    {
        try {
            // Ambil token dari header
            $token = $request->bearerToken();
            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token not found.'
                ], 400);
            }

            // Ambil payload untuk mendapatkan username
            $payload = JWTAuth::setToken($token)->getPayload();
            $username = $payload->get('username');

            // Tentukan Redis Key berdasarkan username
            $redisKey = "admin:token:$username";

            // Hapus token dari Redis
            if (Redis::exists($redisKey)) {
                Redis::del($redisKey);
            }

            // Blacklist token agar tidak bisa digunakan lagi
            JWTAuth::invalidate($token);

            return response()->json([
                'success' => true,
                'message' => 'Logout successful'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred during logout.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}