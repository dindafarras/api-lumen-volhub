<?php
namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\Skill;
use App\Models\Mitra;
use App\Models\Kegiatan;
use App\Models\Pendaftar;
use App\Models\Experience;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Date;

use Illuminate\Support\Facades\Validator;

use Illuminate\Support\Facades\Redis;

use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;


class UserApiController extends Controller
{
    //Login User
    public function loginUser(Request $request)
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
                'message' => "Too many login attempts. Please try again in $ttl seconds",
            ], 429);
        }

        try {

            $redisKey = "user:token:$username";
            if (Redis::exists($redisKey)) {
                $token = Redis::get($redisKey);

                $user = User::where('username', $username)->first();
                if (!$user) {
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
                        'id' => $user->id,
                        'username' => $user->username,
                        'nama_user' => $user->nama_user
                    ]
                ], 200);
            }

            $user = User::where('username', $username)->first();
            if (!$user || !Hash::check($credentials['password'], $user->password)) {

                $attempts = Redis::incr($attemptKey);
                Redis::expire($attemptKey, 3600);

                if ($attempts >= 5) {
                    Redis::setex($blockKey, 300, true);
                    Redis::del($attemptKey);
                    return response()->json([
                        'success' => false,
                        'message' => '"Too many login attempts. You are blocked for 5 minutes',
                    ], 429);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Login failed, incorrect username or password',
                    'attempts_left' => 5 - $attempts,
                ], 401);
            }

            $token = JWTAuth::claims([
                'username' => $username,
                'iat' => time(),
            ])->fromUser($user);

            Redis::setex($redisKey, 3600, $token);

            return response()->json([
                'success' => true,
                'message' => 'Login successful (new token created)',
                'token' => $token,
                'data' => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'nama_user' => $user->nama_user
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

    // Regitrasi User
    public function registrasiUser(Request $request) 
    {
        try {   
            $validator = Validator::make($request->all(), [
                'nama_user' => 'required|max:50',
                'username' => 'required|max:50',
                'email_user' => 'required|email',
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
                'nama_user.required' => "Volunteer's name is required",
                'nama_user.max' => "Volunteer's name cannot be more than 50 characters",
                'username.required' => 'Username is required.',
                'username.max' => 'Username cannot be more than 50 characters',
                'email_user.required' => 'Email is required',
                'email_user.email' => 'Invalid email format',
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

            $registrasi = new User;
            $registrasi->nama_user = $request->nama_user;
            $registrasi->username = $request->username;
            $registrasi->email_user = $request->email_user;
            $registrasi->password = Hash::make($request->password);
            $registrasi->nomor_telephone = $request->nomor_telephone;

            $existing_username = User::where('username', $request->username)->first();
            if ($existing_username) {
                return response()->json([
                    'success' => false,
                    'message' => 'Username is already taken',
                    'status' => 'error'
                ], 400);
            }

            $registrasi-> save();

            Redis::del('user:all');

            return response()->json([
                'success' => true,
                'message' => 'Registration successful',
                'data' => $registrasi
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Registration failed',
                'error'=>$e->getMessage() 
            ], 500);
        }
    }

    // Kelola profile user
    public function profile() 
    {
        $userId = auth('user')->id();

        $key = "user:profile:{$userId}";
        $userData = Redis::get($key);

        if (!$userData){
            $user = User::with('experiences', 'skills', 'pendaftars')->find($userId);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => "Nope, we couldn't find that ID. It's either gone or never existed"
                ], 404);
            }

            Redis::setex($key, 3600, json_encode($user));

            return response()->json([
                'success' => true,
                'message' => 'The volunteer data has been successfully retrieved',
                'data' => $user
            ], 200);
        } else {
            $user = json_decode($userData, true);
            return response()->json([
                'success' => true,
                'message' => 'The volunteer data has been successfully retrieved (Redis)',
                'data' => $user
            ], 200);
        }
    }

    public function editProfile(Request $request)
    {
        try {
            $userId = $request->query('userId');
            
            $authenticatedUserId = auth()->user()->id;
            if ($authenticatedUserId != $userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission',
                ], 403);
            }

            $user = User::find($userId);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => "Nope, we couldn't find that ID. It's either gone or never existed",
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'username' => 'nullable|max:50',
                'email_user' => 'nullable|email',
                'nama_user' => 'nullable|max:50',
                'nomor_telephone' => 'nullable|regex:/^[0-9]{10,15}$/',
                'pendidikan_terakhir' => 'nullable|in:SD,SMP,SMA/SMK,Diploma (D1 - D4),Sarjana (S1),Magister (S2),Doktor (S3)',
                'gender' => 'nullable|in:Laki-laki,Perempuan',
                'domisili' => 'nullable|string|max:255',
                'deskripsi' => 'nullable|string',
                'bio' => 'nullable|string',
                'usia' => 'nullable|integer',
                'instagram' => 'nullable|url',
                'linkedIn' => 'nullable|url',
                'password' => 'nullable|min:8|max:255|regex:/[A-Z]/|regex:/[0-9]/|regex:/[\W_]/',
                'cv' => 'nullable|file|mimes:pdf|max:2048',
                'foto_profile' => 'nullable|image|mimes:jpg,jpeg,png|max:2048'
            ], [
                'username.max' => 'Username cannot be more than 50 characters',
                'email_user.email' => 'Invalid email format',
                'nama_user.max' => "Volunteer's name cannot be more than 50 characters",
                'nomor_telephone.regex' => 'Phone number must be numeric and between 10 to 15 digits long',
                'pendidikan_terakhir.in' => 'Latest education is not valid',
                'gender.in' => 'Gender is not valid',
                'usia.integer' => 'Age must be a number',
                'instagram.url' => 'Instagram URL is not valid',
                'linkedIn.url' => 'LinkedIn URL is not valid',
                'password.min' => 'Password must be at least 8 characters long',
                'password.max' => 'Password cannot be more than 255 characters',
                'password.regex' => 'Password must contain at least one uppercase letter, one number, and one symbol',
                'cv.file' => 'CV must be a file',
                'cv.mimes' => 'CV must be a PDF file.',
                'cv.max' => 'CV size must not exceed 2MB',
                'foto_profile.image' => 'Profile photo must be an image',
                'foto_profile.mimes' => 'Profile photo must be a JPG, JPEG, or PNG file',
                'foto_profile.max' => 'Profile photo size must not exceed 2MB'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user->username = $request->username ?? $user->username;

            $existing_username = User::where('username', $request->username)->first();
            if ($existing_username) {
                return response()->json([
                    'success' => false,
                    'message' => 'Username is already taken',
                    'status' => 'error'
                ], 400);
            }
            
            $user->email_user = $request->email_user ?? $user->email_user;
            $user->nama_user = $request->nama_user ?? $user->nama_user;
            $user->nomor_telephone = $request->nomor_telephone ?? $user->nomor_telephone;
            $user->pendidikan_terakhir = $request->pendidikan_terakhir ?? $user->pendidikan_terakhir;
            $user->gender = $request->gender ?? $user->gender;
            $user->domisili = $request->domisili ?? $user->domisili;
            $user->deskripsi = $request->deskripsi ?? $user->deskripsi;
            $user->bio = $request->bio ?? $user->bio;
            $user->usia = $request->usia ?? $user->usia;
            $user->instagram = $request->instagram ?? $user->instagram;
            $user->linkedIn = $request->linkedIn ?? $user->linkedIn;
            
            // Hash password jika diubah
            if ($request->filled('password')) {
                $user->password = Hash::make($request->password);
            }

            // Upload CV
            if ($request->hasFile('cv')) {
                $this->handleFileUpload($request->file('cv'), 'cv', $user, 'cv');
            }

            // Upload foto profile
            if ($request->hasFile('foto_profile')) {
                $this->handleFileUpload($request->file('foto_profile'), 'foto-profile', $user, 'foto_profile');
            }

            // Simpan perubahan pada user
            $user->save();

            Redis::del("user:all", "user:profile:{$userId}", "admin:DetailUser:{$userId}");

            return response()->json([
                'success' => true,
                'message' => 'Successfully edited the volunteer data',
                'data'=>$user
            ], 200);
        } catch (\Exception $e){
            return response()->json([
                'success' => false,
                'message' => 'Failed to edit the volunteer data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Kelola pendaftaran
    public function applyActivity(Request $request) 
    {
        try {
            $userId = $request->query('userId');
            $idActivity = $request->query('idActivity');
            
            $authenticatedUserId = auth()->user()->id;
            if ($authenticatedUserId != $userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission',
                ], 403);
            }

            $user = User::find($userId);
            $activity = Kegiatan::find($idActivity);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            if (!$activity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Activity not found',
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'motivasi' => 'required|max:255',
            ], [
                'motivasi.required' => 'Motivation is required',
                'motivasi.max' => 'Motivation cannot be more than 255 characters'              
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $apply = new Pendaftar;
            $apply->motivasi = $request->motivasi;
            $apply->status_applicant = 'In-review';
            $apply->id_user = $user->id;
            $apply->id_kegiatan = $activity->id_kegiatan;

            $existingApplication = Pendaftar::where('id_user', $user->id)
                                    ->where('id_kegiatan', $activity->id_kegiatan)
                                    ->first();

            if ($existingApplication) {
                return response()->json([
                    'success' => false,
                    'message' => 'You already apply for this activity',
                ], 400);
            }

            if ($request->hasFile('cv')) {
                $this->handleFileUpload($request->file('cv'), 'cv', $user, 'cv');
                $user->save();
            } else if (!$user->cv) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have a CV yet. Please upload a new CV',
                ], 400);
            }

            $apply->save();

            $employerId = $activity->id_mitra;
            Redis::del("mitra:pendaftar", "employer:applicants:{$employerId}");

            return response()->json([
                'success' => true,
                'message' => 'Registration was successful',
                'data' => $apply
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to complete the registration',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Kelola Skill
    public function addSkill(Request $request) 
    {
        try {
            $userId = $request->query('userId');
            
            $authenticatedUserId = auth()->user()->id;
            if ($authenticatedUserId != $userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission',
                ], 403);
            }
            $user = User::find($userId);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'nama_skill' => 'max:30',
            ], [
                'nama_skill.max' => 'Skill cannot more than 30 characters'              
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            $skill = Skill::firstOrCreate(['nama_skill' => $request->input('nama_skill')]);
            $user->skills()->attach($skill->id_skill);

            Redis::del("user:profile:{$userId}");

            return response()->json([
                'success' => true,
                'message' => 'Successfully added skill',
                'data' => $skill,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add skill',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function deleteSkill(Request $request) 
    {
        try {
            $userId = $request->query('userId');
            $idSkill = $request->query('idSkill');
            
            $authenticatedUserId = auth()->user()->id;
            if ($authenticatedUserId != $userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission',
                ], 403);
            }

            $user = User::find($userId);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            $skill = $user->skills()->find($idSkill);
            if (!$skill) {
                return response()->json([
                    'success' => false,
                    'message' => 'Skill not found in this user'
                ], 404);
            }

            $user->skills()->detach($skill->id_skill);

            $otherUsers = $skill->users()->count();

            if ($otherUsers > 0) {
                return response()->json([
                    'success' => true,
                    'message' => 'Skill removed from this user',
                    'data' => $skill
                ], 200);
            }

            $skill->delete();
            
            Redis::del("user:profile:{$userId}");

            return response()->json([
                'success' => true,
                'message' => 'Skill successfully removed',
                'data' => $skill
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove skill',
                'error' => $e->getMessage()
            ], 500);
        } 
    }

    // Kelola Experience
    public function addExperience(Request $request) 
    {
        try {
            $userId = $request->query('userId');
            
            $authenticatedUserId = auth()->user()->id;
            if ($authenticatedUserId != $userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission',
                ], 403);
            }

            $user = User::find($userId);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'judul_kegiatan' => 'required|max:30',
                'lokasi_kegiatan' => 'required|max:30',
                'tgl_mulai' => 'required|date',
                'tgl_selesai' => 'required|date',
                'deskripsi' => 'required|max:255',
                'mitra' => 'required|max:50',
            ], [
                'judul_kegiatan.required' => 'Activity name is required',
                'judul_kegiatan.max' => 'Activity name cannot be more than 30 character',
                'lokasi_kegiatan.required' => 'Activity location is required',
                'lokasi_kegiatan.max' => 'Activity location cannot be more than 30 characters',
                'tgl_mulai.required' => 'Start date of activity is required',
                'tgl_mulai.date' => 'Start date of activity must be in YYYY-MM-DD format',
                'tgl_selesai.required' => 'End date of activity is required',
                'tgl_selesai.date' => 'End date of activity must be in YYYY-MM-DD format',
                'deskripsi.required' => 'Description is required',
                'deskripsi.max' => 'Description cannot be more than 255 characters',
                'mitra.required' => "Company's name is required",
                'mitra.max' => "Company's name cannot be more than 50 characters",             
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $experience = new Experience;
            $experience->id_user= $user->id;
            $experience->judul_kegiatan= $request->judul_kegiatan;
            $experience->lokasi_kegiatan = $request->lokasi_kegiatan;
            $experience->tgl_mulai = $request->tgl_mulai;
            $experience->tgl_selesai = $request->tgl_selesai;
            $experience->deskripsi = $request->deskripsi;
            $experience->mitra = $request->mitra;

            $experience->save();

            Redis::del("user:profile:{$userId}");

            return response()->json([
                'success' => true,
                'message' => 'Experience successfully added',
                'data' => $experience
            ], 201);

        } catch (\Exception $e) {
            return response()->json ([
                'success' => false,
                'message' => 'Failed to add new experience',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function editExperience(Request $request) 
    {
        try {
            $userId = $request->query('userId');
            $experienceId = $request->query('experienceId');
            
            $authenticatedUserId = auth()->user()->id;
            if ($authenticatedUserId != $userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission',
                ], 403);
            }

            $user = User::find($userId);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            $experience = $user->experiences()->find($experienceId);

            if (!$experience) {
                return response()->json([
                    'success' => false,
                    'message' => 'Experience not found for this user'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'judul_kegiatan' => 'nullable|max:30',
                'lokasi_kegiatan' => 'nullable|max:30',
                'tgl_mulai' => 'nullable|date',
                'tgl_selesai' => 'nullable|date',
                'deskripsi' => 'nullable|max:255',
                'mitra' => 'nullable|max:50',
            ], [
                'judul_kegiatan.max' => 'Activity name cannot be more than 30 characters',
                'lokasi_kegiatan.max' => 'Activity location cannot be more than 30 characters',
                'tgl_mulai.date' => 'Start date of activity must be in YYYY-MM-DD format',
                'tgl_selesai.date' => 'End date of activity must be in YYYY-MM-DD format',
                'deskripsi.max' => 'Description cannot be more than 255 characters',
                'mitra.max' => "Company's name cannot be more than 50 characters",             
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 422);
            }

            $experience->id_user= $user->id;
            $experience->judul_kegiatan= $request->judul_kegiatan ?? $experience->judul_kegiatan;
            $experience->lokasi_kegiatan = $request->lokasi_kegiatan ?? $experience->lokasi_kegiatan;
            $experience->tgl_mulai = $request->tgl_mulai ?? $experience->tgl_mulai;
            $experience->tgl_selesai = $request->tgl_selesai ?? $experience->tgl_selesai;
            $experience->deskripsi = $request->deskripsi ?? $experience->deskripsi;
            $experience->mitra = $request->mitra ?? $experience->mitra;

            $experience->save();

            Redis::del("user:profile:{$userId}");

            return response()->json([
                'success' => true,
                'message' => 'Experience successfully updated',
                'data' => $experience
            ], 200);
        } catch (\Exception $e) {
            return response()->json ([
                'success' => false,
                'message' => 'Failed to update experience',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function deleteExperience(Request $request) 
    {
        try {
            $userId = $request->query('userId');
            $experienceId = $request->query('experienceId');
            
            $authenticatedUserId = auth()->user()->id;
            if ($authenticatedUserId != $userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission',
                ], 403);
            }

            $user = User::find($userId);

            if (!$user) {
                return response()->json([
                    'success' =>  false,
                    'message' => 'User not found'
                ], 404);
            }

            $experience = $user->experiences()->find($experienceId);

            if (!$experience) {
                return response()->json([
                    'success' => false,
                    'message' => 'Experience not found for this user'
                ], 404);
            }

            $experience->delete();

            Redis::del("user:profile:{$userId}");

            return response()->json([
                'success' => true,
                'message' => 'Experience successfully removed',
                'data' => $experience
            ], 200);
        } catch (\Exception $e) {
            return response()->json ([
                'success' =>  false,
                'message' => 'Failed to remove experience',
                'error' => $e->getMessage()
            ], 500);

        }
    }

    // INI GUNANYA UNTUK HAPUS FILE LAMA KALAU TERJADI PERUBAHAN
    private function handleFileUpload($file, $directory, $user, $attribute)
    {
        // Hapus file lama jika ada
        if ($user->$attribute) {
            $oldFilePath = storage_path("app/public/{$directory}/{$user->$attribute}");
            if (File::exists($oldFilePath)) {
                File::delete($oldFilePath);
            }
        }

        // Upload file baru
        $fileName = Date::now()->timestamp . '-' . $file->getClientOriginalName();
        $file->storeAs($directory, $fileName, 'public');

        // Simpan nama file baru ke user
        $user->$attribute = $fileName;
    }

    public function activities() 
    {
        $key = "all:activities";
        $allActivitiesData = Redis::get($key);

        if (!$allActivitiesData) {
            $activities = Kegiatan::select('id_kegiatan', 'nama_kegiatan', 'sistem_kegiatan', 'tgl_penutupan', 'deskripsi')->get();

            if (!$activities) {
                return response()->json([
                    'success' => false,
                    'message' => 'No activities available',
                ], 404);
            }

            Redis::setex("$key", 3600, json_encode($activities));
            return response()->json([
                'success' => true,
                'message' => 'Successfully fetched all activities',
                'data' => $activities
            ], 200);
        } else {
            $activities = json_decode($allActivitiesData);
            return response()->json([
                'success' => true,
                'message' => 'Successfully fetched all activities (Redis)',
                'data' => $activities
            ]);
        }
    }

    public function detailActivity(Request $request) 
    {
        $activityId = $request->query('activityId');
        
        $key = "detail:acitivity:{$activityId}";
        $detailActivitiData = Redis::get($key);

        if(!$detailActivitiData) 
        {
            $activity = Kegiatan::with('benefits', 'kriterias')
                                    ->find($activityId);

            Redis::setex("$key", 3600, json_encode($activity));

            return response()->json([
                'success' => true,
                'message' => 'Successfully fetched activity details',
                'data' => $activity
            ], 200);
        } else {
            $activity = json_decode($detailActivitiData);
            return response()->json([
                'success' => true,
                'message' => 'Successfully fetched activity details (Redis)',
                'data' => $activity
            ], 200);
        }
    }
    public function employers() 
    {
        $key = "all:employers";
        $allEmployersData = Redis::get($key);

        if (!$allEmployersData) {
            $employers = Mitra::select('id_mitra', 'logo', 'nama_mitra', 'industri', 'alamat')->get();

            if (!$employers) {
                return response()->json([
                    'success' => false,
                    'message' => 'No Employers available',
                ], 404);
            }

            Redis::setex("$key", 3600, json_encode($employers));
            return response()->json([
                'success' => true,
                'message' => 'Successfully fetched all employers',
                'data' => $employers
            ], 200);

        } else {
            $employers = json_decode($allEmployersData);
            return response()->json([
                'success' => true,
                'message' => 'Successfully fetched all employers (Redis)',
                'data' => $employers
            ], 200);
        }
        
    }

    public function detailEmployer(Request $request) 
    {
        $employerId = $request->query('employerId');
        
        $key = "detail:employer:{$employerId}";
        $detailEmployerData = Redis::get($key);

        if(!$detailEmployerData) 
        {
            $employer = Mitra::find($employerId);
            if (!$employer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employer not found',
                ], 404);
            }

            Redis::setex("$key", 3600, json_encode($employer));

            return response()->json([
                'success' => true,
                'message' => 'Successfully fetched employer details',
                'data' => $employer
            ], 200);
        } else {
            $employer = json_decode($detailEmployerData);
            return response()->json([
                'success' => true,
                'message' => 'Successfully fetched employer details (Redis)',
                'data' => $employer
            ], 200);
        }
    }

    public function logout(Request $request)
    {
        try {
            $token = $request->bearerToken();
            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token not found.'
                ], 400);
            }

            $payload = JWTAuth::setToken($token)->getPayload();
            $username = $payload->get('username');

            $redisKey = "user:token:$username";

            if (Redis::exists($redisKey)) {
                Redis::del($redisKey);
            }

            JWTAuth::invalidate($token);

            return response()->json([
                'success' => true,
                'message' => 'Logout successful'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An error occurred during logout',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}