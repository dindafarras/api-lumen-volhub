<?php
namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use App\Models\Admin;
use App\Models\User;
use App\Models\Mitra;
use App\Models\Pendaftar;
use App\Models\Kegiatan;
use App\Models\Kategori;
use App\Models\Benefit;
use App\Models\Kriteria;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Date;

use Carbon\Carbon;

use Illuminate\Support\Facades\Validator;

use Illuminate\Support\Facades\Redis;

use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;


class EmployerApiController extends Controller
{
    public function login(Request $request) 
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $username = $request->input('username');
        $credentials = $request->only('username', 'password');

        $attemptKey = "login:attempts:$username";
        $blockKey = "login:blocked:$username";

        if (Redis::exists($blockKey)) {
            $ttl = Redis::ttl($blockKey);
            return response()->json([
                'success' => false,
                'message' => "Too many login attempts. Please try again in $ttl seconds.",
            ], 429);
        }

        try {
            $redisKey = "mitra:token:$username";
            if (Redis::exists($redisKey)) {
                $token = Redis::get($redisKey);
                
                $mitra = Mitra::where('username', $username)->first();
                if (!$mitra) {
                    return response()->json([
                        'success' => false,
                        'message' => 'User not found.',
                    ], 404);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Login successful (Redis)',
                    'token' => $token,
                    'data' => [
                        'id_mitra' => $mitra->id_mitra,
                        'username' => $mitra->username,
                        'nama_mitra' => $mitra->nama_mitra
                    ]
                ], 200);
            }

            $mitra = Mitra::where('username', $username)->first();
            if (!$mitra || !Hash::check($credentials['password'], $mitra->password)) {
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
                    'message' => '"Login failed, incorrect username or password.',
                    'attempts_left' => 5 - $attempts,
                ], 401);
            }

            $token = JWTAuth::claims([
                'username' => $username,
                'iat' => time(),
            ])->fromUser($mitra);

            Redis::setex($redisKey, 3600, $token);

            return response()->json([
                'success' => true,
                'message' => 'Login successful (new token created).',
                'token' => $token,
                'data' => [
                    'id_mitra' => $mitra->id_mitra,
                    'username' => $mitra->username,
                    'nama_mitra' => $mitra->nama_mitra
                ]
            ], 200);
        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create token.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function registrasi(Request $request) 
    {
        try {
            $validator = Validator::make($request->all(), [
                'nama_mitra' => 'required|max:50',
                'username' => 'required|max:50',
                'email_mitra' => 'required|email',
                'password' => [
                    'required',
                    'min:8',
                    'max:255',
                    'regex:/[A-Z]/',    
                    'regex:/[0-9]/',    
                    'regex:/[\W_]/',    
                ],
                'nomor_telephone' => [
                    'required',
                    'regex:/^[0-9]{10,15}$/',
                ]
            ], [
                'nama_mitra.required' => 'Company name is required',
                'nama_mitra.max' => 'Company name cannot be more than 50 characters',
                'username.required' => 'Username is required',
                'username.max' => 'Username cannot be more than 50 characters',
                'email_mitra.required' => 'Email company is required',
                'email_mitra.email' => 'Invalid email format',
                'password.required' => 'Password is required',
                'password.min' => 'Password must be at least 8 characters long',
                'password.max' => 'Password cannot be more than 255 characters',
                'password.regex' => 'Password must contain at least one uppercase letter, one number, and one symbol',
                'nomor_telephone.required' => 'Phone number is required',
                'nomor_telephone.regex' => 'Phone number must be numeric and between 10 to 15 digits long'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $registrasi = new Mitra;
            $registrasi->nama_mitra = $request->nama_mitra;
            $registrasi->username = $request->username;
            $registrasi->email_mitra = $request->email_mitra;
            $registrasi->password = Hash::make($request->password);
            $registrasi->nomor_telephone = $request->nomor_telephone;

            $existing_username = Mitra::where('username', $request->username)->first();
            if ($existing_username) {
                return response()->json([
                    'success' => false,
                    'message' => 'Username is already taken',
                    'status' => 'error'
                ], 400);
            }

            $registrasi-> save();
            
            Redis::del('mitra:all', 'all:employers');

            return response()->json([
                'success' => true,
                'message' => 'Registration successful',
                'data'=>$registrasi
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Registration failed',
                'error'=>$e->getMessage() 
            ], 500);
        }
    }

    // Kelola Profile
    public function profile() 
    {
        $employerId = auth('employer')->id();
        
        $key = "employer:profile:{$employerId}";
        $employerData = Redis::get($key);

        if (!$employerData) {
            $employer = Mitra::find($employerId);
            if (!$employer) {
                return response()->json([
                    'success' => false,
                    'message' => "Nope, we couldn't find that ID. It's either gone or never existed",
                ], 404);
            }

            Redis::setex($key, 3600, json_encode($employer));

            return response()->json([
                'success' => true,
                'message' => 'Employer data successfully retrieved',
                'data' => $employer
            ]);
        } else {
            $employer = json_decode($employerData, true);
            return response()->json([
                'success' => true,
                'message' => 'Employer data successfully retrieved (Redis)',
                'data' => $employer,
            ], 200);
        }
    }

    public function editProfile(Request $request) 
    {
        try {
            $employerId = $request->query('employerId');
            
            $authenticatedEmployerId = auth('employer')->user()->id_mitra;
                if ($authenticatedEmployerId != $employerId) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You do not have permission',
                    ], 403);
                }

            $employer = Mitra::find($employerId);

            $validator = Validator::make($request->all(), [
                'username' => 'nullable|max:50',
                'password' => 'nullable|min:8|max:255|regex:/[A-Z]/|regex:/[0-9]/|regex:/[\W_]/',
                'email_mitra' => 'nullable|email',
                'nama_mitra' => 'nullable|max:50',
                'bio' => 'nullable|max:50',
                'industri' => 'nullable|max:30',
                'ukuran_perusahaan' => 'nullable|regex:/^[0-9]+$/',
                'situs' => 'nullable',
                'deskripsi' => 'nullable|max:255',
                'alamat' => 'nullable|max:255',
                'nomor_telephone' => 'nullable|regex:/^[0-9]{10,15}$/',
                'gambar' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
                'logo' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
            ], [
                'username.max' => 'Username cannot be more than 50 characters',
                'password.min' => 'Password must be at least 8 characters long',
                'password.max' => 'Password cannot be more than 255 characters',
                'password.regex' => 'Password must contain at least one uppercase letter, one number, and one symbol',
                'email_mitra.email' => 'Invalid email format',
                'nama_mitra.max' => 'Company name cannot be more than 50 characters',
                'bio.max' => 'Bio cannot be more than 50 characters',
                'industri.max' => 'Industry cannot be more than 50 characters',
                'ukuran_perusahaan.regex' => 'Company size must be a number',
                'deskripsi.max' => 'Description cannot be more than 255 characters',
                'alamat.max' => 'Address cannot be more than 255 characters',
                'nomor_telephone.regex' => 'Phone number must be numeric and between 10 to 15 digits long',
                'gambar.image' => 'The file must be an image',
                'gambar.mimes' => 'The image must be a JPG, JPEG, or PNG file',
                'gambar.max' => 'The image size cannot exceed 2MB', 
                'logo.image' => 'The logo must be an image.',
                'logo.mimes' => 'The logo must be a JPG, JPEG, or PNG file',
                'logo.max' => 'The logo size cannot exceed 2MB'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 400);
            }

            $employer->username = $request->username ?? $employer->username;

            $existing_username = Mitra::where('username', $request->username)->first();
            if ($existing_username) {
                return response()->json([
                    'status' => false,
                    'message' => 'Username is already taken',
                ], 400);
            }

            $employer->nama_mitra = $request->nama_mitra ?? $employer->nama_mitra;
            $employer->email_mitra = $request->email_mitra ?? $employer->email_mitra;
            $employer->bio = $request->bio ?? $employer->bio;
            $employer->industri = $request->industri ?? $employer->industri;
            $employer->ukuran_perusahaan = $request->ukuran_perusahaan ?? $employer->ukuran_perusahaan;
            $employer->situs = $request->situs ?? $employer->situs;
            $employer->deskripsi = $request->deskripsi ?? $employer->deskripsi;
            $employer->alamat = $request->alamat ?? $employer->alamat;
            $employer->nomor_telephone = $request->nomor_telephone ?? $employer->nomor_telephone;
            
            if ($request->hasFile('gambar')) {
                $this->handleFileUpload($request->file('gambar'), 'gambar', $employer, 'gambar');
            }

            if ($request->hasFile('logo')) {
                $this->handleFileUpload($request->file('logo'), 'logo', $employer, 'logo');
            }

            if ($request->filled('password')) {
                $employer->password = Hash::make($request->password);
            }

            $employer->save();

            Redis::del("employer:all", "employer:profile:{$employerId}", "admin:detailMitra:{$employerId}", "detail:employer:{$employerId}");

            return response()->json([
                'success' => true,
                'message' => 'Successfully updated employer profile',
                'data' => $employer
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update employer profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Kelola Activity
    public function activities(Request $request) 
    {
        $employerId = $request->query('employerId');
        
        $authenticatedEmployerId = auth('employer')->user()->id_mitra;
            if ($authenticatedEmployerId != $employerId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission',
                ], 403);
            }

        $key = "employer:activities:{$employerId}";
        $employerActivitiesData = Redis::get($key);

        if(!$employerActivitiesData) {
            $employer = Mitra::find($employerId);

            if (!$employer) {
                return response()->json([
                    'success' => false,
                    'message' => "Nope, we couldn't find that ID. It's either gone or never existed",
                ], 404);
            }

            $activities = Kegiatan::where('id_mitra', $employer->id_mitra)
                                        ->get();

            Redis::setex("$key", 3600, json_encode($activities));
            return response()->json([
                'success' => true,
                'message' => 'Successfully retrieved all activities for this company',
                'data' => $activities
            ], 200);
        } else {
            $activities = json_decode($employerActivitiesData);
            return response()->json([
                'success' => true,
                'message' => 'Successfully retrieved all activities for this company (Redis)',
                'data' => $activities
            ]);
        }
    }

    public function detailActivity(Request $request) 
    {
        $employerId = $request->query('employerId');
        $activityId = $request->query('activityId');
        
        $authenticatedEmployerId = auth('employer')->user()->id_mitra;
            if ($authenticatedEmployerId != $employerId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission',
                ], 403);
            }

        $key = "detail:activity:{$employerId}:{$activityId}";
        $detailActivitiData = Redis::get($key);

        if(!$detailActivitiData) 
        {
            $employer = Mitra::find($employerId);

            if (!$employer) {
                return response()->json([
                    'success' => false,
                    'message' => "Nope, we couldn't find that ID. It's either gone or never existed",
                ], 404);
            }

            $activity = Kegiatan::with('benefits', 'kriterias')
                                    ->where('id_mitra', $employer->id_mitra)->find($activityId);

            Redis::setex("$key", 3600, json_encode($activity));

            return response()->json([
                'success' => true,
                'message' => 'Successfully retrieved activity details',
                'data' => $activity
            ], 200);
        } else {
            $activity = json_decode($detailActivitiData);
            return response()->json([
                'success' => true,
                'message' => 'Successfully retrieved activity details (Redis)',
                'data' => $activity
            ]);
        }
    }

    public function addActivity(Request $request) 
    {
        try{
            $employerId = $request->query('employerId');
            
            $authenticatedEmployerId = auth('employer')->user()->id_mitra;
            if ($authenticatedEmployerId != $employerId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission',
                ], 403);
            }

            $employer = Mitra::find($employerId);
            if (!$employer) {
                return response()->json([
                    'success' => false,
                    'message' => "Nope, we couldn't find that ID. It's either gone or never existed"
                ], 404);
            }

            $category = Kategori::find($request->id_kategori);
            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Category not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'id_kategori' => 'required',
                'lokasi_kegiatan' => 'required|max:50',
                'nama_kegiatan' => 'required|max:50',
                'lama_kegiatan' => 'required|max:50',
                'sistem_kegiatan' => 'required|in:Online,Offline',
                'deskripsi' => 'required|max:255',
                'tgl_penutupan' => 'required|date',
                'tgl_kegiatan' => 'required|date',
            ], [
                'id_kategori.required' => 'Category is required',
                'lokasi_kegiatan.required' => 'Location is required',
                'lokasi_kegiatan.max' => 'Activity location cannot be more than 50 characters',
                'nama_kegiatan.required' => 'Activity name is required',
                'nama_kegiatan.max' => 'Activity name cannot be more than 50 characters',
                'lama_kegiatan.required' => 'Activity duration is required',
                'lama_kegiatan.max' => 'Activity duration cannot be more than 50 characters',
                'sistem_kegiatan.required' => 'Activity system is required',
                'sistem_kegiatan.in' => 'Activity system must be either Online or Offline',
                'deskripsi.required' => 'Activity description is required',
                'deskripsi.max' => 'Activity description cannot be more than 255 characters',
                'tgl_penutupan.required' => 'The closing date is required',
                'tgl_penutupan.date' => 'The closing date must be filled in the format YYYY-MM-DD',
                'tgl_kegiatan.required' => 'The activity date is required to be filled in',
                'tgl_kegiatan.date' => 'The activity date must be filled in the format YYYY-MM-DD'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 400);
            }
            
            $activity = new Kegiatan;
            $activity->id_mitra = $employer->id_mitra;
            $activity->id_kategori = $request->id_kategori;
            $activity->nama_kegiatan = $request->nama_kegiatan;
            $activity->lokasi_kegiatan = $request->lokasi_kegiatan;
            $activity->lama_kegiatan = $request->lama_kegiatan;
            $activity->sistem_kegiatan = $request->sistem_kegiatan;
            $activity->deskripsi = $request->deskripsi;
            $activity->tgl_penutupan = $request->tgl_penutupan;
            $activity->tgl_kegiatan = $request->tgl_kegiatan;

            $activity->save();

            Redis::del("employer:activities:{$employerId}", "all:activities");

            return response()->json([
                'success' => true,
                'message' => 'New activity has been successfully added',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add new activity',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function editActivity(Request $request) 
    {
        try{
            $employerId = $request->query('employerId');
            $activityId = $request->query('activityId');
            
            $authenticatedEmployerId = auth('employer')->user()->id_mitra;
            if ($authenticatedEmployerId != $employerId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission',
                ], 403);
            }

            $employer = Mitra::find($employerId);
            if (!$employer) {
                    return response()->json([
                        'success' => false,
                        'message' => "Nope, we couldn't find that ID. It's either gone or never existed."
                    ], 404);
            }

            $activity = Kegiatan::find($activityId);
            if (!$activity) {
                return response()->json([
                    'success' =>false,
                    'message' => 'Activity not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'id_kategori' => 'nullable',
                'lokasi_kegiatan' => 'nullable|max:50',
                'nama_kegiatan' => 'nullable|max:50',
                'lama_kegiatan' => 'nullable|max:50',
                'sistem_kegiatan' => 'nullable|in:Online,Offline',
                'deskripsi' => 'nullable|max:255',
                'tgl_penutupan' => 'nullable|date',
                'tgl_kegiatan' => 'nullable|date',
            ], [
                'lokasi_kegiatan.max' => 'Activity location cannot be more than 50 characters',
                'nama_kegiatan.max' => 'Activity name cannot be more than 50 characters',
                'lama_kegiatan.max' => 'Activity duration cannot be more than 50 characters',
                'sistem_kegiatan.in' => 'Activity system must be either Online or Offline',
                'deskripsi.max' => 'Activity description cannot be more than 255 characters',
                'tgl_penutupan.date' => 'The closing date must be in the format YYYY-MM-DD',
                'tgl_kegiatan.date' => 'The activity date must be in the format YYYY-MM-DD'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 400);
            }

            $activity->id_mitra = $employer->id_mitra;
            $activity->id_kategori = $request->id_kategori ?? $activity->id_kategori;
            $activity->nama_kegiatan = $request->nama_kegiatan ?? $activity->nama_kegiatan;
            $activity->lokasi_kegiatan = $request->lokasi_kegiatan ?? $activity->lokasi_kegiatan;
            $activity->lama_kegiatan = $request->lama_kegiatan ?? $activity->lama_kegiatan;
            $activity->sistem_kegiatan = $request->sistem_kegiatan ?? $activity->sistem_kegiatan;
            $activity->deskripsi = $request->deskripsi ?? $activity->deskripsi;
            $activity->tgl_penutupan = $request->tgl_penutupan ?? $activity->tgl_penutupan;
            $activity->tgl_kegiatan = $request->tgl_kegiatan ?? $activity->tgl_kegiatan;

            $activity->save();

            Redis::del("employer:activities:{$employerId}", "detail:activity:{$employerId}:{$activityId}", "all:activities", "detail:acitivity:{$activityId}");

            return response()->json([
                'success' => true,
                'message' => 'Activity successfully updated',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update activity',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function deleteActivity(Request $request) 
    {
        try {
            $employerId = $request->query('employerId');
            $activityId = $request->query('activityId');
            
            $authenticatedEmployerId = auth('employer')->user()->id_mitra;
            if ($authenticatedEmployerId != $employerId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission',
                ], 403);
            }

            $employer = Mitra::find($employerId);
            if (!$employer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employer not found'
                ], 404);
            }

            $activity = Kegiatan::where('id_mitra', $employer->id_mitra)->find($activityId);
            if (!$activity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Activity not found',
                ], 404);
            }
            
            $activity->delete();

            Redis::del("employer:activities:{$employerId}", "all:activities");

            return response()->json([
                'success' => true,
                'message' => 'Activity successfully deleted',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete activity',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Kelola Benefit
    public function addBenefit(Request $request)
    {
        try {
            $employerId = $request->query('employerId');
            $activityId = $request->query('activityId');
            
            $authenticatedEmployerId = auth('employer')->user()->id_mitra;
            if ($authenticatedEmployerId != $employerId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission',
                ], 403);
            }

            $employer = Mitra::find($employerId);
            if (!$employer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employer not found'
                ], 404);
            }

            $activity = Kegiatan::where('id_mitra', $employer->id_mitra)->find($activityId);
            if (!$activity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Activity not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'nama_benefit' => 'max:255',
            ], [
                'nama_benefit.max' => 'Benefit cannot be more than 255 characters'              
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 400);
            }

            $benefit = Benefit::firstOrCreate(['nama_benefit' => $request->input('nama_benefit')]);
            $activity->benefits()->attach($benefit->id_benefit);

            $benefit->save();

            Redis::del("detail:activity:{$employerId}:{$activityId}", "detail:acitivity:{$activityId}");

            return response()->json([
                'success' => true,
                'message' => 'Benefit successfully added to this activity',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add benefit',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function deleteBenefit(Request $request) 
    {
        try {
            $employerId = $request->query('employerId');
            $activityId = $request->query('activityId');
            $benefitId = $request->query('benefitId');

            $authenticatedEmployerId = auth('employer')->user()->id_mitra;
            if ($authenticatedEmployerId != $employerId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission',
                ], 403);
            }

            $employer = Mitra::find($employerId);
            if (!$employer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employer not found'
                ], 404);
            }

            $activity = Kegiatan::where('id_mitra', $employer->id_mitra)
                                    ->find($activityId);
            if (!$activity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Activity not found'
                ], 404);
            }
            
            $benefit = $activity->benefits()->find($benefitId);
            if (!$benefit) {
                return response()->json([
                    'success' => false,
                    'message' => 'Benefit not found'
                ], 404);
            }

            // Hapus hubungan benefit dengan kegiatan
            $activity->benefits()->detach($benefit->id_benefit);

            // Periksa apakah benefit masih digunakan oleh kegiatan lain
            $otherActivity = $benefit->kegiatans()->count();

            if ($otherActivity > 0) {
                return response()->json([
                    'success' => true,
                    'message' => 'Benefit removed from this activity'
                ], 200);
            }

            $benefit->delete();

            Redis::del("detail:activity:{$employerId}:{$activityId}", "detail:acitivity:{$activityId}");

            return response()->json([
                'success' => true,
                'message' => 'Benefit successfully deleted'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete benefit',
                'error' => $e->getMessage()
            ], 500);
        } 
    }

    // Kelola Requirement
    public function addRequirement(Request $request) 
    {
        try {
            $employerId = $request->query('employerId');
            $activityId = $request->query('activityId');
            
            $authenticatedEmployerId = auth('employer')->user()->id_mitra;
            if ($authenticatedEmployerId != $employerId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission',
                ], 403);
            }

            $employer = Mitra::find($employerId);
            if (!$employer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employer not found'
                ], 404);
            }

            $activity = Kegiatan::where('id_mitra', $employer->id_mitra)
                                    ->find($activityId);
            if (!$activity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Activity not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'nama_kriteria' => 'max:255',
            ], [
                'nama_kriteria.max' => 'Requirement cannot be more than 255 characters.'              
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 400);
            }

            $requirement = Kriteria::firstOrCreate(['nama_kriteria' => $request->input('nama_kriteria')]);
            $activity->kriterias()->attach($requirement->id_kriteria);

            $requirement->save(); 

            Redis::del("detail:activity:{$employerId}:{$activityId}","detail:acitivity:{$activityId}");

            return response()->json([
                'success' => true,
                'message' => 'Requirement successfully added to this activity',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add requirement',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function deleteRequirement(Request $request) 
    {
        try {
            $employerId = $request->query('employerId');
            $activityId = $request->query('activityId');
            $requirementId = $request->query('requirementId');

            $authenticatedEmployerId = auth('employer')->user()->id_mitra;
            if ($authenticatedEmployerId != $employerId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission',
                ], 403);
            }

            $employer = Mitra::find($employerId);
            if (!$employer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employer not found'
                ], 404);
            }

            $activity = Kegiatan::where('id_mitra', $employer->id_mitra)
                                    ->find($activityId);
            if (!$activity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Activity not found'
                ], 404);
            }
            
            $requirement = $activity->kriterias()->find($requirementId);
            if (!$requirement) {
                return response()->json([
                    'success' => false,
                    'message' => 'Requirement not found'
                ], 404);
            }

            // Hapus hubungan benefit dengan kegiatan
            $activity->kriterias()->detach($requirement->id_kriteria);

            // Periksa apakah benefit masih digunakan oleh kegiatan lain
            $otherActivity = $requirement->kegiatans()->count();

            if ($otherActivity > 0) {
                return response()->json([
                    'success' => true,
                    'message' => 'Requirement removed from this activity'
                ], 200);
            }

            $requirement->delete();

            Redis::del("detail:activity:{$employerId}:{$activityId}", "detail:acitivity:{$activityId}");

            return response()->json([
                'success' => true,
                'message' => 'Requirement successfully deleted'
            ], 200); 
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete requirement',
                'error' => $e->getMessage()
            ], 500);
        } 
    }

    // Kelola Pendaftar
    public function applicants(Request $request) 
    {
        $employerId = $request->query('employerId');
        
        $authenticatedEmployerId = auth('employer')->user()->id_mitra;
            if ($authenticatedEmployerId != $employerId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission',
                ], 403);
            }

        $key="employer:applicants:{$employerId}";
        $applicantData = Redis::get($key);

        if(!$applicantData) {
            $employer = Mitra::find($employerId);

            if(!$employer) {
                return response()->json([
                    'success' => false,
                    'message' => "Nope, we couldn't find that ID. It's either gone or never existed"
                ], 404);
            }

            $activities = Kegiatan::where('id_mitra', $employer->id_mitra)->pluck('id_kegiatan');

            $applicants = Pendaftar::whereIn('id_kegiatan', $activities)
                                        ->select('id_user', 'id_kegiatan', 'status_applicant', 'tgl_pendaftaran')
                                        ->get();

            Redis::setex("$key", 3600, json_encode($applicants));
            return response()->json([
                'success' => true,
                'message' => 'Successfully retrieved all applicants for this employer',
                'data' => $applicants
            ], 200);
        } else {
            $applicants = json_decode($applicantData);
            return response()->json([
                'success' => true,
                'message' => 'Successfully retrieved all applicants for this employer (Redis)',
                'data' => $applicants
            ]);
        }
    }

    public function detailApplicant(Request $request)
    {
        $employerId = $request->query('employerId');
        $userId = $request->query('userId');
        
        try {
            $authenticatedEmployerId = auth('employer')->user()->id_mitra;
            if ($authenticatedEmployerId != $employerId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission',
                ], 403);
            }

            $key = "detail:applicant:{$employerId}:{$userId}";
            $detailApplicantData = Redis::get($key);

            if (!$detailApplicantData) {
                $employer = Mitra::find($employerId);
                if (!$employer) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Employer not found'
                    ], 404);
                }

                $activities = Kegiatan::where('id_mitra', $employer->id_mitra)->pluck('id_kegiatan');

                if ($activities->isEmpty()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No activity associated with this employer'
                    ], 404);
                }

                $applicant = Pendaftar::where('id_pendaftar', $userId)
                                    ->whereIn('id_kegiatan', $activities)
                                    ->first();

                if (!$applicant) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Applicant not found'
                    ], 404);
                }

                Redis::setex($key, 3600, json_encode($applicant));

                return response()->json([
                    'success' => true,
                    'message' => 'Applicant details successfully retrieved',
                    'data' => $applicant
                ], 200);
            } else {
                $applicant = json_decode($detailApplicantData);
                return response()->json([
                    'success' => true,
                    'message' => 'Applicant details successfully retrieved (Redis)',
                    'data' => $applicant
                ], 200);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving applicant details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateApplicant(Request $request) 
    {
        try{ 
            $employerId = $request->query('employerId');
            $userId = $request->query('userId');
            $activityId = $request->query('activityId');

            $authenticatedEmployerId = auth('employer')->user()->id_mitra;
            if ($authenticatedEmployerId != $employerId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission',
                ], 403);
            }

            $employer = Mitra::find($employerId);
            if (!$employer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employer not found'
                ], 404);
            }

            $activity = Kegiatan::where('id_mitra', $employer->id_mitra)
                            ->where('id_kegiatan', $activityId)
                            ->first();

            if (!$activity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Activity not found'
                ], 404);
            }

            $applicant = Pendaftar::where('id_kegiatan', $activity->id_kegiatan)
                                ->where('id_pendaftar', $userId)
                                ->first();

            if (!$applicant) {
                return response()->json([
                    'success' => false,
                    'message' => 'No applicants found for this activity'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'status_applicant' => 'required',
                'note_to_applicant' => 'required|max:255',
            ], [
                'status_applicant.required' => 'Applicant status is required',
                'note_to_applicant.required' => 'Hire/Reject must include a note for the applicant',
                'note_to_applicant.max' => 'Note for applicant cannot be more than 255 characters'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 400);
            }

            $applicant->status_applicant = $request->status_applicant;

            switch (ucfirst(strtolower($request->status_applicant))) {
                case 'Shortlist':
                    $applicant->note_to_applicant = "You have been shortlisted.";
                    break;

                case 'Interview':
                    $applicant->note_to_applicant = "You have advanced to the Interview stage.";
                    break;

                case 'Hire':
                case 'Reject':
                    if (!$request->has('note_to_applicant') || empty($request->note_to_applicant)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Note for Hire or Reject status must be provided.'
                        ], 400);
                    }
                    $applicant->note_to_applicant = $request->note_to_applicant;
                    break;

                default:
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid status_applicant value.'
                    ], 400);
            }

            $applicant->tgl_note = Date::now();
            $applicant->save();

            Redis::del("user:profile:{$userId}", "employer:applicants:{$employerId}", "detail:applicant:{$employerId}:{$userId}");

            return response()->json([
                'success' => true,
                'message' => 'Applicant update successful',
                'data'=>$applicant
            ], 200);
        } catch (\Exception $e){
            return response()->json([
                'success' => false,
                'message' => 'Failed to update registration',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateInterview(Request $request) 
    {
        try{
            $employerId = $request->query('employerId');
            $userId = $request->query('userId');
            $activityId = $request->query('activityId');
            
            $authenticatedEmployerId = auth('employer')->user()->id_mitra;
            if ($authenticatedEmployerId != $employerId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission',
                ], 403);
            }
            
            $employer = Mitra::find($employerId);
            if (!$employer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employer not found'
                ], 404);
            }

            $activity = Kegiatan::where('id_mitra', $employer->id_mitra)
                            ->where('id_kegiatan', $activityId)
                            ->first();

            if (!$activity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Activity not found'
                ], 404);
            }

            $applicant = Pendaftar::where('id_kegiatan', $activity->id_kegiatan)
                                ->where('id_pendaftar', $userId)
                                ->first();

            if (!$applicant) {
                return response()->json([
                    'success' => false,
                    'message' => 'No applicants found for this activity.'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'tgl_interview' => 'required|date',
                'lokasi_interview' => 'required|max:255',
                'interview_time' => 'required|date_format:H:i',
                'note_interview' => 'nullable|max:255',
            ], [
                'tgl_interview.required' => 'Interview date is required',
                'tgl_interview.date' => 'Interview date must be in the format YYYY-MM-DD',
                'lokasi_interview.required' => 'Interview location is required',
                'lokasi_interview.max' => 'Interview location cannot be more than 255 characters',
                'interview_time.required' => 'Interview time is required',
                'interview_time.date_format' => 'Interview time must be in the format HH:MM',
                'note_interview.max' => 'Interview note cannot be more than 255 characters'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 400);
            }

            $applicant->status_applicant = 'Interview';
            $applicant->tgl_interview = $request->tgl_interview;
            $applicant->lokasi_interview = $request->lokasi_interview;
            $applicant->interview_time = $request->interview_time;
            if (empty($request->input('tgl_interview'))) {
                $applicant->status_interview = 'Not scheduled yet';
            } else {
                $tglInterview = Carbon::parse($request->input('tgl_interview'));
            
                if ($tglInterview->isPast()) {
                    $applicant->status_interview = 'Interview Complete';
                } elseif ($tglInterview->isFuture() || $tglInterview->isToday()) {
                    $applicant->status_interview = 'On progress';
                }
            }
            $applicant->note_interview = $request->note_interview;
            $applicant->note_to_applicant = "You have entered the Interview stage";
            $applicant->tgl_note = Date::now();

            $applicant->save();

            Redis::del("user:profile:{$userId}", "employer:applicants:{$employerId}", "detail:applicant:{$employerId}:{$userId}");

            return response()->json([
                'success' => true,
                'message' => 'Applicant data successfully updated',
                'data' => $applicant
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update interview schedule',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function logout(Request $request)
    {
        try {
            $token = $request->bearerToken();

            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token not found',
                ], 400);
            }

            $payload = JWTAuth::setToken($token)->getPayload();
            $username = $payload->get('username');

            // Hapus token dari Redis
            $redisKey = "mitra:token:$username";
            if (Redis::exists($redisKey)) {
                Redis::del($redisKey);

                return response()->json([
                    'success' => true,
                    'message' => 'Logout successful',
                ], 200);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Token not found in Redis',
                ], 404);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to logout',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    // INI GUNANYA UNTUK HAPUS FILE LAMA KALAU TERJADI PERUBAHAN
    private function handleFileUpload($file, $directory, $model, $attribute)
    {
        // Hapus file lama jika ada
        if ($model->$attribute) {
            $oldFilePath = storage_path("app/public/{$directory}/{$model->$attribute}");
            if (File::exists($oldFilePath)) {
                File::delete($oldFilePath);
            }
        }

        // Upload file baru
        $fileName = Date::now()->timestamp . '-' . $file->getClientOriginalName();
        $file->storeAs($directory, $fileName, 'public');

        // Simpan nama file baru ke atribut model
        $model->$attribute = $fileName;
    }
}