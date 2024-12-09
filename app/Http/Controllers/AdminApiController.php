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

        $credentials = $request->only('username', 'password');

        try {
            if (!$token = auth('admin')->attempt($credentials)) {
                return response()->json(['message' => 'Login gagal, username atau password salah'], 401);
            }
        } catch (JWTException $e) {
            return response()->json([
                'message' => 'Gagal membuat token'
            ], 500);
        }

        return response()->json([
            'message' => 'Login berhasil',
            'token' => $token,
        ], 200);
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
                    'message' => '"Nope, we couldn’t find that ID. It’s either gone or never existed 🙄"'
                ], 404);
            }
    
            // Simpan ke Redis (3600 detik)
            Redis::setex($key, 3600, json_encode($admin));

            return response()->json([
                'success' => true,
                'message' => 'Data admin berhasil diambil dari database',
                'data' => $admin
            ]);
        } else {
            $admin = json_decode($adminData, true);
            return response()->json([
                'message' => 'Data admin masih ada di Redis',
                'data' => $admin
            ], 200);
        }
    }
    
    public function editProfile(Request $request, $idAdmin)
    {
        try {
            $admin = Admin::find($idAdmin);

            if (!$admin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nope, we couldn’t find that ID. It’s either gone or never existed 🙄',
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
                'username.max' => 'Username tidak boleh lebih dari 255 karakter.',
                'password.min' => 'Password harus memiliki minimal 8 karakter.',
                'password.max' => 'Password tidak boleh lebih dari 255 karakter.',
                'password.regex' => 'Password harus mengandung setidaknya satu huruf kapital, satu angka, dan satu simbol.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validasi gagal',
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
                'message' => 'Data berhasil diperbarui.',
                'data' => $admin
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal melakukan perubahan profile admin',
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
                    'message' => 'Category never existed'
                ], 404);
            }
    
            // Simpan ke Redis (60 detik)
            Redis::setex("$key", 3600, json_encode($category));
            return response()->json([
                'success' => true,
                'message' => 'Data berhasil diambil dari database',
                'data' => $category
            ]);
        } else {
            // Ambil data dari Redis
            $category = json_decode($categoryData);
            return response()->json([
                'success' => true,
                'message' => 'Data berhasil diambil dari redis',
                'data' => $category
            ]);
        }

        return response()->json ([
            'success' => true,
            'data' => $category
        ], 200);
    }

    public function addCategory (Request $request) 
    {
        try {
            $category = new Kategori;

            // Validasi input
            $validator = Validator::make($request->all(), [
                'nama_kategori' => 'required|max:255',
            ], [
                'nama_kategori.required' => 'Kategori wajib diisi.',
                'nama_kategori.max' => 'Kategori tidak boleh lebih dari 255 karakter.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $category->nama_kategori = $request->nama_kategori;
            $category->save();

            // Bersihkan cache daftar kategori di Redis
            Redis::del('admin:category:all');

            return response()->json([
                'message' => 'Kategori baru behasil ditambahkan',
                'data' => $category
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal menambahkan kategori baru',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function editCategory (Request $request, $idCategory) 
    {
        try {
            $category = Kategori::find($idCategory);

            if (!$category) {
                return response()->json(['message' => 'Kategori tidak ditemukan'], 404);
            }

            // Validasi input
            $validator = Validator::make($request->all(), [
                'nama_kategori' => 'required|max:255',
            ], [
                'nama_kategori.required' => 'Kategori wajib diisi.',
                'nama_kategori.max' => 'Kategori tidak boleh lebih dari 255 karakter.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $category->nama_kategori = $request->nama_kategori;
            $category->save();

            Redis::del('admin:category:all');

            return response()->json([
                'message' => 'Berhasil edit kategori',
                'data' => $category
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal edit kategori',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteCategory($idCategory) 
    {
        try {
            $category = Kategori::find($idCategory);

            if (!$category) {
                return response()->json(['message' => 'Kategori tidak ditemukan'], 404);
            }

            $category->delete();

            Redis::del('admin:category:all');

            return response()->json([
                'message' => 'Berhasil menghapus kategori'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal menghapus Kategori',
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
                $allUser = User::select('username', 'nama_user', 'email_user')->get();

                if (!$allUser) {
                    return response()->json([
                        'message' => 'Tidak ada data user'
                    ], 404);
                }
        
                // Simpan ke Redis (60 detik)
                Redis::setex($key, 3600, json_encode($allUser));
                return response()->json([
                    'success' => true,
                    'message' => 'Data berhasil diambil dari database',
                    'data' => $allUser
                ]);
            } else {
                // Ambil data dari Redis
                $allUser = json_decode($userData);
                return response()->json([
                    'success' => true,
                    'message' => 'Data berhasil diambil dari redis',
                    'data' => $allUser
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal mengambil data user',
                'error' => $e->getMessage()
            ], 200);
        }
    }

    public function detailUser($idUser)
    {
        try{
            $key = "admin:DetailUser:{$idUser}";
            $detailUserData = Redis::get($key);

            if (!$detailUserData) {
                $detailUser = User::find($idUser);

                if (!$detailUser) {
                    return response()->json(['message' => 'User tidak ditemukan'], 404);
                }

                Redis::setex($key, 3600, $detailUser->toJson());
                return response()->json([
                    'success' => true,
                    'message' => 'Baru membuat key untuk Redis, data ini dari database',
                    'data' => $detailUser
                ], 200);
            } else {
                $detailUser = json_decode($detailUserData);
                return response()->json([
                    'success' => true,
                    'message' => 'Data berhasil diambil dari redis',
                    'data' => $detailUser
                ], 200);
            }
        } catch(\Exception $e) {
            return response()->json([
                'message' => 'Gagal mengambil data detail user',
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
                $allMitra = Mitra::select('username', 'nama_mitra', 'email_mitra')->get();

                if(!$allMitra){
                    return response()->json([
                        'message' => 'Mitra never existed'
                    ], 404);
                }
                Redis::setex($key, 3600, json_encode($allMitra));
                
                return response()->json([
                    'success' => true,
                    'message' => 'Data berhasil diambil dari database',
                    'data' => $allMitra
                ], 200);
            } else {
                $allMitra = json_decode($mitraData);
                return response()->json([
                    'success' => true,
                    'message' => 'Data berhasil diambil dari redis',
                    'data' => $allMitra,
                ],200);
            }
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal mengambil data mitra',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function detailMitra($idMitra)
    {
        try {
            $key = "admin:detailMitra:{$idMitra}";
            $detailMitraData = Redis::get($key);

            if(!$detailMitraData) {
                $detailMitra = Mitra::find($idMitra);

                if (!$detailMitra) {
                    return response()->json(['message' => 'Mitra tidak ditemukan'], 404);
                }

                Redis::setex($key, 3600, $detailMitra->toJson());
                return response()->json([
                    'success' => true,
                    'message' => 'Data berhasil diambil dari database',
                    'data' => $detailMitra
                ], 200);
            } else {
                $detailMitra = json_decode($detailMitraData);
                return response()->json([
                    'success' => true,
                    'message' => 'Data berhasil diambil dari redis',
                    'data' => $detailMitra
                ], 200);
            }
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal mengambil data detail mitra',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}