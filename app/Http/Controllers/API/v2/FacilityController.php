<?php

namespace App\Http\Controllers\API\v2;

use App\Helpers\AuthHelper;
use App\Helpers\FacilityHelper;
use App\Helpers\RatingHelper;
use App\Helpers\RoleHelper;
use App\Role;
use App\Transfer;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Facility;
use Jose\Component\KeyManagement\JWKFactory;

/**
 * Class FacilityController
 *
 * @package App\Http\Controllers\API\v2
 */
class FacilityController extends APIController
{
    /**
     * @return array|string
     *
     * @SWG\Get(
     *     path="/facility",
     *     summary="(DONE) Get list of VATUSA facilities",
     *     description="(DONE) Get list of VATUSA facilities",
     *     produces={"application/json"},
     *     tags={"facility"},
     *     @SWG\Response(
     *         response="200",
     *         description="OK",
     *         @SWG\Schema(
     *             type="array",
     *             @SWG\Items(
     *                 ref="#/definitions/Facility"
     *             ),
     *         ),
     *         examples={
     *              "application/json":{
     *                      {"id": "HCF","name": "Honolulu CF","url": "http://www.hcfartcc.net","region": 7},
     *                      {"id":"ZAB","name":"Albuquerque ARTCC","url":"http:\/\/www.zabartcc.org","region":8},
     *              }
     *         }
     *     )
     * )
     */
    public function getIndex()
    {
        $data = Facility::where("active", 1)->get()->toArray();

        return response()->json($data);
    }

    /**
     * @param $id
     *
     * @return \Illuminate\Http\JsonResponse
     *
     * @SWG\Get(
     *     path="/facility/{id}",
     *     summary="(DONE) Get facility information",
     *     description="(DONE) Get facility information",
     *     produces={"application/json"},
     *     tags={"facility"},
     *     @SWG\Parameter(name="id", in="query", description="Facility IATA ID", required=true, type="string"),
     *     @SWG\Response(
     *         response="404",
     *         description="Not found or not active",
     *         @SWG\Schema(ref="#/definitions/error"),
     *         examples={"application/json":{"status"="error","msg"="Facility not found or not active"}},
     *     ),
     *     @SWG\Response(
     *         response="200",
     *         description="OK",
     *         @SWG\Schema(
     *             type="object",
     *             @SWG\Property(property="facility", ref="#/definitions/Facility"),
     *             @SWG\Property(
     *                 property="roles",
     *                 type="array",
     *                 @SWG\Items(
     *                     ref="#/definitions/Role",
     *                 ),
     *             ),
     *             @SWG\Property(
     *                 property="stats",
     *                 type="object",
     *                 @SWG\Property(property="controllers", type="integer", description="Number of controllers on facility roster"),
     *                 @SWG\Property(property="pendingTransfers", type="integer", description="Number of pending transfers to facility"),
     *             ),
     *         ),
     *         examples={
     *              "application/json":{
     *                      {"id":"HCF","name":"Honolulu CF","url":"http:\/\/www.hcfartcc.net","role":{{"cid":1245046,"name":"Toby Rice","role":"MTR"},{"cid":1152158,"name":"Taylor Broad","role":"MTR"},{"cid":1147076,"name":"Dave Mayes","role":"ATM"},{"cid":1245046,"name":"Toby Rice","role":"DATM"},{"cid":1289149,"name":"Israel Reyes","role":"FE"},{"cid":1152158,"name":"Taylor Broad","role":"WM"}},"stats":{"controllers":19,"pendingTransfers":0}}
     *              }
     *         }
     *     )
     * )
     */
    public function getFacility($id)
    {
        $facility = Facility::find($id);
        if (!$facility || !$facility->active) {
            return response()->api(
                generate_error("Facility not found or not active", true), 404
            );
        }

        if (\Cache::has("facility.$id.info")) {
            return \Cache::get("facility.$id.info");
        }

        $data = [
            'facility' => $facility->toArray(),
            'role' => Role::where('facility', $facility->id)->get()->toArray(),
        ];
        $data['stats']['controllers'] = User::where('facility', $id)->count();
        $data['stats']['pendingTransfers'] = Transfer::where('to', $id)->where(
            'status', Transfer::$pending
        )->count();

        $json = encode_json($data);

        \Cache::put("facility.$id.info", $json, 60);

        return $json;
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @param                          $id
     *
     * @return \Illuminate\Http\JsonResponse
     *
     * @SWG\Put(
     *     path="/facility/{id}",
     *     summary="Update facility information. Requires JWT or Session Cookie",
     *     description="Update facility information. Requires JWT or Session Cookie",
     *     produces={"application/json"},
     *     tags={"facility"},
     *     security={"json","session"},
     *     @SWG\Parameter(name="id", in="path", description="Facility IATA ID", required=true, type="string"),
     *     @SWG\Parameter(name="url", in="formData", description="Change facility URL, role restricted [ATM, DATM, WM]", type="string"),
     *     @SWG\Parameter(name="url", in="formData", description="Request new JWK", type="string"),
     *     @SWG\Parameter(name="apikey", in="formData", type="string", description="Request new API Key for facility, role restricted [ATM, DATM, WM]"),
     *     @SWG\Parameter(name="apikeySandbox", in="formData", type="string", description="Request new Sandbox API Key for facility, role restricted [ATM, DATM, WM]"),
     *     @SWG\Parameter(name="ulsSecret", in="formData", type="string", description="Request new ULS Secret, role restricted [ATM, DATM, WM]"),
     *     @SWG\Parameter(name="ulsReturn", in="formData", type="string", description="Set new ULS return point, role restricted [ATM, DATM, WM]"),
     *     @SWG\Parameter(name="ulsDevReturn", in="formData", type="string", description="Set new ULS developmental return point, role restricted [ATM, DATM, WM]"),
     *     @SWG\Response(
     *         response="401",
     *         description="Unauthenticated",
     *         @SWG\Schema(ref="#/definitions/error"),
     *         examples={"application/json":{"status"="error","msg"="Unauthenticated"}},
     *     ),
     *     @SWG\Response(
     *         response="403",
     *         description="Forbidden",
     *         @SWG\Schema(ref="#/definitions/error"),
     *         examples={"application/json":{"status"="error","msg"="Forbidden"}},
     *     ),
     *     @SWG\Response(
     *         response="404",
     *         description="Not found or not active",
     *         @SWG\Schema(ref="#/definitions/error"),
     *         examples={"application/json":{"status"="error","msg"="Facility not found or not active"}},
     *     ),
     *     @SWG\Response(
     *         response="200",
     *         description="OK",
     *         @SWG\Schema(
     *             type="object",
     *             @SWG\Property(property="status",type="string"),
     *             @SWG\Property(property="apikey",type="string"),
     *             @SWG\Property(property="apikeySandbox",type="string"),
     *             @SWG\Property(property="ulsSecret", type="string"),
     *         ),
     *         examples={"application/json":{"status"="OK"}}
     *     )
     * )
     */
    public function putFacility(Request $request, $id)
    {
        $facility = Facility::find($id);
        if (!$facility || !$facility->active) {
            return response()->api(
                generate_error("Facility not found or not active", true), 404
            );
        }

        if (!RoleHelper::has(\Auth::user()->cid, $id, ["ATM", "DATM", "WM"])
            && !RoleHelper::isVATUSAStaff(\Auth::user()->cid)
        ) {
            return response()->api(generate_error("Forbidden", true), 403);
        }

        $data = [];

        if ($request->has("url")
            && filter_var(
                $request->input("url"), FILTER_VALIDATE_URL
            )
        ) {
            $facility->url = $request->input("url");
            $facility->save();
        }

        if ($request->has("uls2jwk")) {
            $data = JWKFactory::createOctKey(
                env('ULSV2_SIZE', 512),
                ['alg' => env('ULSV2_ALG', 'HS256'), 'use' => 'sig']
            );
            $facility->uls_jwk = encode_json($data);
            $facility->save();
            return response()->json($data);
        }

        if ($request->has("apiv2jwk")) {
            $data = JWKFactory::createOctKey(
                env('APIV2_SIZE', 1024),
                ['alg' => env('APIV2_ALG', 'HS256'), 'use' => 'sig']
            );
            $facility->apiv2_jwk = encode_json($data);
            $facility->save();

            return response()->json($data);
        }

        if ($request->has('apikey')) {
            if (\Auth::check()
                && RoleHelper::has(
                    \Auth::user()->cid, $facility->id, ['ATM', 'DATM', 'WM']
                )
            ) {
                $data['apikey'] = randomPassword(16);
                $facility->apikey = $data['apikey'];
                $facility->save();
            } else {
                return response()->api(generate_error("Forbidden"), 403);
            }
        }

        if ($request->has('apikeySandbox')) {
            if (\Auth::check()
                && RoleHelper::has(
                    \Auth::user()->cid, $facility->id, ['ATM', 'DATM', 'WM']
                )
            ) {
                $data['apikeySandbox'] = randomPassword(16);
                $facility->api_sandbox_key = $data['apikeySandbox'];
                $facility->save();
            } else {
                return response()->api(generate_error("Forbidden"), 403);
            }
        }

        if ($request->has('ulsSecret')) {
            if (\Auth::check()
                && RoleHelper::has(
                    \Auth::user()->cid, $facility->id, ['ATM', 'DATM', 'WM']
                )
            ) {
                $data['ulsSecret'] = substr(hash('sha512', microtime()), -16);
                $facility->uls_secret = $data['ulsSecret'];
                $facility->save();
            } else {
                return response()->api(generate_error("Forbidden"), 403);
            }
        }

        if ($request->has('ulsReturn')) {
            if (\Auth::check()
                && RoleHelper::has(
                    \Auth::user()->cid, $facility->id, ['ATM', 'DATM', 'WM']
                )
            ) {
                $facility->uls_return = $request->input("ulsReturn");
                $facility->save();
            } else {
                return response()->api(generate_error("Forbidden"), 403);
            }
        }

        if ($request->has('ulsDevReturn')) {
            if (\Auth::check()
                && RoleHelper::has(
                    \Auth::user()->cid, $facility->id, ['ATM', 'DATM', 'WM']
                )
            ) {
                $facility->uls_devreturn = $request->input("ulsDevReturn");
                $facility->save();
            } else {
                return response()->api(generate_error("Forbidden"), 403);
            }
        }

        return response()->api(array_merge(['status' => 'OK'], $data));
    }

    /**
     *
     * @SWG\Get(
     *     path="/facility/{id}/email/{templateName}",
     *     summary="(DONE) Get facility's email template. Requires API Key, JWT or Session Cookie",
     *     description="(DONE) Get facility's email template. Requires API Key, JWT or Session Cookie",
     *     produces={"application/json"},
     *     tags={"facility","email"},
     *     @SWG\Parameter(name="id", in="path", description="Facility IATA ID", required=true, type="string"),
     *     @SWG\Parameter(name="templateName", in="path", description="Name of template (welcome, examassigned, examfailed, exampassed)", required=true, type="string"),
     *     @SWG\Response(
     *         response="401",
     *         description="Unauthenticated",
     *         @SWG\Schema(ref="#/definitions/error"),
     *         examples={"application/json":{"status"="error","msg"="Unauthenticated"}},
     *     ),
     *     @SWG\Response(
     *         response="403",
     *         description="Forbidden",
     *         @SWG\Schema(ref="#/definitions/error"),
     *         examples={"application/json":{"status"="error","msg"="Forbidden"}},
     *     ),
     *     @SWG\Response(
     *         response="404",
     *         description="Not found",
     *         @SWG\Schema(ref="#/definitions/error"),
     *         examples={"application/json":{"status"="error","msg"="Facility not found or not active"}},
     *     ),
     *     @SWG\Response(
     *         response="200",
     *         description="OK",
     *         @SWG\Schema(
     *             ref="#/definitions/EmailTemplate"
     *         ),
     *     )
     * )
     */
    public function getEmailTemplate(Request $request, $id, $templateName)
    {
        if (!\Auth::check() && !$request->has("apikey")) {
            return response()->api(generate_error("Unauthenticated"), 401);
        }
        if (\Auth::check() && (!RoleHelper::isSeniorStaff(\Auth::user()->cid, $id, true)
            && !RoleHelper::isVATUSAStaff())
        ) {
            return response()->api(generate_error("Forbidden"), 403);
        }
        $facility = Facility::find($id);
        if (!$facility || $facility->active != 1
            || !in_array(
                $templateName, FacilityHelper::EmailTemplates()
            )
        ) {
            return response()->api(generate_error("Not Found"), 404);
        }

        $template = FacilityHelper::findEmailTemplate($id, $templateName);

        switch($templateName) {
            case 'exampassed':
                $template['variables'] = ['exam_name','instructor_name','correct','possible','score','student_name'];
                break;
            case 'examfailed':
                $template['variables'] = ['exam_name','instructor_name','correct','possible','score','student_name', 'reassign', 'reassign_date'];
                break;
            case 'examassigned':
                $template['variables'] = ['exam_name', 'instructor_name', 'student_name', 'end_date', 'cbt_required', 'cbt_facility', 'cbt_block'];
                break;
            default:
                $template['variables'] = null;
                break;
        }

        return response()->api($template->toArray());
    }

    /**
     *
     * @SWG\Post(
     *     path="/facility/{id}/email/{templateName}",
     *     summary="(DONE) Modify facility's email template. Requires JWT or Session Cookie",
     *     description="(DONE) Modify facility's email template. Requires JWT or Session Cookie",
     *     produces={"application/json"},
     *     tags={"facility","email"},
     *     @SWG\Parameter(name="id", in="query", description="Facility IATA ID", required=true, type="string"),
     *     @SWG\Parameter(name="templateName", in="path", description="Name of template (welcome, examassigned, examfailed, exampassed)", required=true, type="string"),
     *     @SWG\Parameter(name="body", in="formData", description="Text of template", required=true, type="string"),
     *     @SWG\Response(
     *         response="401",
     *         description="Unauthenticated",
     *         @SWG\Schema(ref="#/definitions/error"),
     *         examples={"application/json":{"status"="error","msg"="Unauthenticated"}},
     *     ),
     *     @SWG\Response(
     *         response="403",
     *         description="Forbidden",
     *         @SWG\Schema(ref="#/definitions/error"),
     *         examples={"application/json":{"status"="error","msg"="Forbidden"}},
     *     ),
     *     @SWG\Response(
     *         response="404",
     *         description="Not found or not active",
     *         @SWG\Schema(ref="#/definitions/error"),
     *         examples={"application/json":{"status"="error","msg"="Facility not found or not active"}},
     *     ),
     *     @SWG\Response(
     *         response="200",
     *         description="OK",
     *         @SWG\Schema(
     *             type="object",
     *             @SWG\Property(property="status",type="string"),
     *             @SWG\Property(property="template",type="string"),
     *             @SWG\Property(property="body",type="string"),
     *         ),
     *         examples={"application/json":{"status"="OK"}}
     *     )
     * )
     */
    function postEmailTemplate(Request $request, $id, $templateName)
    {
        if (!\Auth::check()) {
            return response()->api(generate_error("Unauthenticated"), 401);
        }
        if (!RoleHelper::isSeniorStaff(\Auth::user()->cid, $id, true)
            && !RoleHelper::isVATUSAStaff()
        ) {
            return response()->api(generate_error("Forbidden"), 403);
        }
        $facility = Facility::find($id);
        if (!$facility || $facility->active != 1
            || !in_array(
                $templateName, FacilityHelper::EmailTemplates()
            )
        ) {
            return response()->api(generate_error("Not Found"), 404);
        }

        $template = FacilityHelper::findEmailTemplate($id, $templateName);
        $template->body = preg_replace(array('/<(\?|\%)\=?(php)?/', '/(\%|\?)>/'), array('',''), $request->input("body"));
        $template->save();

        return response()->api(['status' => 'OK']);
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @param                          $id
     *
     * @return \Illuminate\Http\JsonResponse
     *
     * @SWG\Get(
     *     path="/facility/{id}/roster",
     *     summary="(DONE) Get facility roster",
     *     description="(DONE) Get facility staff.  If api key is not specified, email properties are defined for role ATM, DATM, TA, INS, WM, or VATUSA STAFF",
     *     produces={"application/json"},
     *     tags={"facility"},
     *     @SWG\Parameter(name="id", in="query", description="Facility IATA ID", required=true, type="string"),
     *     @SWG\Response(
     *         response="404",
     *         description="Not found or not active",
     *         @SWG\Schema(ref="#/definitions/error"),
     *         examples={"application/json":{"status"="error","msg"="Facility not found or not active"}},
     *     ),
     *     @SWG\Response(
     *         response="200",
     *         description="OK",
     *         @SWG\Schema(
     *             type="array",
     *             @SWG\Items(
     *                 ref="#/definitions/User",
     *             ),
     *         ),
     *     )
     * )
     */
    public function getRoster(Request $request, $id)
    {
        $facility = Facility::find($id);
        if (!$facility || $facility->active != 1) {
            return response()->api(generate_error("Not found"), 404);
        }
        $roster = $facility->members->toArray();
        if (!$request->has("apikey")
            && !(\Auth::check()
                && RoleHelper::isFacilityStaff(
                    \Auth::user()->cid, \Auth::user()->facility
                ))
        ) {
            $count = count($roster);
            for ($i = 0; $i < $count; $i++) {
                $roster[$i]['email'] = null;
            }
        }
        return response()->json($roster);
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @param string                   $id
     * @param integer                  $cid
     *
     * @return \Illuminate\Http\JsonResponse
     *
     * @SWG\Delete(
     *     path="/facility/{id}/roster/{cid}",
     *     summary="(DONE) Delete member from facility roster. JWT or Session Cookie required",
     *     description="(DONE) Delete member from facility roster.  JWT or Session Cookie required (required role: ATM, DATM, VATUSA STAFF)",
     *     produces={"application/json"},
     *     tags={"facility"},
     *     security={"jwt","session"},
     *     @SWG\Parameter(name="id", in="query", description="Facility IATA ID", required=true, type="string"),
     *     @SWG\Parameter(name="cid", in="query", description="CID of controller", required=true, type="integer"),
     *     @SWG\Parameter(name="reason", in="formData", description="Reason for deletion", required=true, type="string"),
     *     @SWG\Response(
     *         response="400",
     *         description="Malformed request, missing required parameter",
     *         @SWG\Schema(ref="#/definitions/error"),
     *         examples={"application/json":{"status"="error","msg"="Malformed request"}},
     *     ),
     *     @SWG\Response(
     *         response="401",
     *         description="Unauthenticated",
     *         @SWG\Schema(ref="#/definitions/error"),
     *         examples={"application/json":{"status"="error","msg"="Unauthenticated"}},
     *     ),
     *     @SWG\Response(
     *         response="403",
     *         description="Forbidden -- needs to have role of ATM, DATM or VATUSA Division staff member",
     *         @SWG\Schema(ref="#/definitions/error"),
     *         examples={"application/json":{"status"="error","message"="Forbidden"}},
     *     ),
     *     @SWG\Response(
     *         response="404",
     *         description="Not found or not active",
     *         @SWG\Schema(ref="#/definitions/error"),
     *         examples={"application/json":{"status"="error","msg"="Facility not found or not active"}},
     *     ),
     *     @SWG\Response(
     *         response="200",
     *         description="OK",
     *         @SWG\Schema(ref="#/definitions/OK"),
     *         examples={"application/json":{"status"="OK"}}
     *     )
     * )
     */
    public function deleteRoster(Request $request, string $id, int $cid)
    {
        $facility = Facility::find($id);
        if (!$facility || !$facility->active) {
            return response()->api(
                generate_error("Facility not found or not active"), 404
            );
        }

        if (!RoleHelper::isSeniorStaff(\Auth::user()->cid, $id, false)) {
            return response()->api(generate_error("Forbidden"), 403);
        }

        $user = User::where('cid', $cid)->first();
        if (!$user || $user->facility != $facility->id) {
            return response()->api(
                generate_error("User not found or not in facility"), 404
            );
        }

        if (!$request->has("reason") || !$request->filled("reason")) {
            return response()->api(generate_error("Malformed request"), 400);
        }

        $user->removeFromFacility(
            \Auth::user()->cid, $request->input("reason")
        );

        return response()->api(["status" => "OK"]);
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @param string                   $id
     *
     * @return \Illuminate\Http\JsonResponse
     *
     * @SWG\Get(
     *     path="/facility/{id}/transfers",
     *     summary="(DONE) Get pending transfers. Requires JWT, API Key or Session Cookie",
     *     description="(DONE) Get pending transfers. Requires JWT, API Key or Session Cookie",
     *     produces={"application/json"},
     *     tags={"facility"},
     *     security={"jwt","session","apikey"},
     *     @SWG\Parameter(name="id", in="query", description="Facility IATA ID", required=true, type="string"),
     *     @SWG\Response(
     *         response="400",
     *         description="Malformed request, missing required parameter",
     *         @SWG\Schema(ref="#/definitions/error"),
     *         examples={"application/json":{"status"="error","msg"="Malformed request"}},
     *     ),
     *     @SWG\Response(
     *         response="403",
     *         description="Forbidden -- needs to be a staff member, other than mentor",
     *         @SWG\Schema(ref="#/definitions/error"),
     *         examples={"application/json":{"status"="error","message"="Forbidden"}},
     *     ),
     *     @SWG\Response(
     *         response="404",
     *         description="Not found or not active",
     *         @SWG\Schema(ref="#/definitions/error"),
     *         examples={"application/json":{"status"="error","msg"="Facility not found or not active"}},
     *     ),
     *     @SWG\Response(
     *         response="200",
     *         description="OK",
     *         @SWG\Schema(
     *             type="object",
     *             @SWG\Property(property="status", type="string"),
     *             @SWG\Property(property="transfers", type="array",
     *                 @SWG\Items(
     *                     type="object",
     *                     @SWG\Property(property="id", type="integer", description="Transfer ID"),
     *                     @SWG\Property(property="cid", type="integer"),
     *                     @SWG\Property(property="name", type="string"),
     *                     @SWG\Property(property="rating", type="string", description="Short string rating (S1, S2)"),
     *                     @SWG\Property(property="intRating", type="integer", description="Numeric rating (OBS = 1, etc)"),
     *                     @SWG\Property(property="date", type="string", description="Date transfer submitted (YYYY-MM-DD)"),
     *                 ),
     *             ),
     *         ),
     *         examples={"application/json":{"status":"OK","transfers":{"id":991,"cid":876594,"name":"Daniel Hawton","rating":"C1","intRating":5,"date":"2017-11-18"}}}
     *     )
     * )
     */
    public function getTransfers(Request $request, string $id)
    {
        $facility = Facility::find($id);
        if (!$facility || !$facility->active) {
            return response()->api(
                generate_error("Facility not found or not active"), 404
            );
        }

        if (!$request->has("apikey") && !\Auth::check()) {
            return response()->api(generate_error("Unauthenticated"), 401);
        }

        if (!$request->has("apikey")
            && !RoleHelper::isFacilityStaff(
                \Auth::user()->cid, $id
            )
            && !RoleHelper::isVATUSAStaff(\Auth::user()->cid)
        ) {
            return response()->api(generate_error("Forbidden"), 403);
        }

        $transfers = Transfer::where("to", $facility->id)->where(
            "status", Transfer::$pending
        )->get();
        $data = [];
        foreach ($transfers as $transfer) {
            $data[] = [
                'id' => $transfer->id,
                'cid' => $transfer->cid,
                'name' => $transfer->user->fullname(),
                'rating' => RatingHelper::intToShort($transfer->user->rating),
                'intRating' => $transfer->user->rating,
                'date' => $transfer->created_at->format('Y-m-d')
            ];
        }

        return response()->api(['status' => 'OK', 'transfers' => $data]);
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @param string                   $id
     * @param int                      $transferId
     *
     * @return \Illuminate\Http\JsonResponse
     *
     * @SWG\Put(
     *     path="/facility/{id}/transfers/{transferId}",
     *     summary="Modify transfer request.  JWT or Session cookie required.",
     *     description="Modify transfer request.  JWT or Session cookie required. (required role: self, ATM, DATM, VATUSA STAFF)",
     *     produces={"application/json"},
     *     tags={"facility"},
     *     security={"jwt","session"},
     *     @SWG\Parameter(name="id", in="query", description="Facility IATA ID", required=true, type="string"),
     *     @SWG\Parameter(name="transferId", in="query", description="Transfer ID", type="integer", required=true),
     *     @SWG\Parameter(name="action", in="formData", type="string", enum={"approve","reject","cancel"}, description="Action to take on transfer request. Valid values: approve, reject, cancel (for self requests only)"),
     *     @SWG\Parameter(name="reason", in="formData", type="string", description="Reason for transfer request rejection [required for rejections or cancellations]"),
     *     @SWG\Response(
     *         response="400",
     *         description="Malformed request, missing required parameter",
     *         @SWG\Schema(ref="#/definitions/error"),
     *         examples={"application/json":{"status"="error","msg"="Malformed request"}},
     *     ),
     *     @SWG\Response(
     *         response="403",
     *         description="Forbidden -- needs to be a staff member, other than mentor",
     *         @SWG\Schema(ref="#/definitions/error"),
     *         examples={"application/json":{"status"="error","message"="Forbidden"}},
     *     ),
     *     @SWG\Response(
     *         response="404",
     *         description="Not found or not active",
     *         @SWG\Schema(ref="#/definitions/error"),
     *         examples={"application/json":{"status"="error","msg"="Facility not found or not active"}},
     *     ),
     *     @SWG\Response(
     *         response="410",
     *         description="Gone",
     *         @SWG\Schema(ref="#/definitions/error"),
     *         examples={"application/json":{"status"="error","msg"="Transfer is not pending"}},
     *     ),
     *     @SWG\Response(
     *         response="200",
     *         description="OK",
     *         @SWG\Schema(ref="#/definitions/OK"),
     *         examples={"application/json":{"status"="OK"}}
     *     )
     * )
     */
    public function putTransfer(Request $request, string $id, int $transferId)
    {
        $facility = Facility::find($id);
        if (!$facility || !$facility->active) {
            return response()->api(
                generate_error("Facility not found or not active"), 404
            );
        }

        if (!\Auth::check()) {
            return response()->api(generate_error("Unauthenticated"), 401);
        }

        if (!RoleHelper::isSeniorStaff(\Auth::user()->cid, $facility->id, false)
            && !RoleHelper::isVATUSAStaff(\Auth::user()->cid)
        ) {
            return response()->api(generate_error("Forbidden"), 403);
        }

        $transfer = Transfer::find($transferId);
        if (!$transfer) {
            return response()->api(
                generate_error("Transfer request not found"), 404
            );
        }

        if ($transfer->status !== Transfer::$pending) {
            return response()->api(
                generate_error("Transfer is not pending"), 410
            );
        }

        if ($transfer->to !== $facility->id) {
            return response()->api(generate_error("Forbidden"), 403);
        }

        if (!in_array($request->input("action"), ["accept", "reject"])
            || ($request->input("action") === "reject"
                && !$request->filled(
                    "reason"
                ))
        ) {

            return response()->api(generate_error("Malformed request"), 400);
        }

        if ($request->input("action") === "accept") {
            $transfer->accept(\Auth::user()->cid);
        } else {
            $transfer->reject(\Auth::user()->cid, $request->input("reason"));
        }

        return response()->api(['status' => "OK"]);
    }
}
